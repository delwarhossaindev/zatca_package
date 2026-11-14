<?php

namespace delwarhossaindev\Zatca;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use delwarhossaindev\Zatca\Data\Address;
use delwarhossaindev\Zatca\Data\Buyer;
use delwarhossaindev\Zatca\Data\InvoiceData;
use delwarhossaindev\Zatca\Data\InvoiceLine;
use delwarhossaindev\Zatca\Exceptions\ZatcaException;
use delwarhossaindev\Zatca\Models\ZatcaDevice;
use delwarhossaindev\Zatca\Models\ZatcaDocument;
use delwarhossaindev\Zatca\Support\Certificate;
use delwarhossaindev\Zatca\Support\CsrGenerator;
use delwarhossaindev\Zatca\Support\InvoiceSigner;
use delwarhossaindev\Zatca\Support\InvoiceXmlBuilder;
use delwarhossaindev\Zatca\Support\ZatcaApi;

/**
 * Onboarding (CSR -> Compliance CSID -> compliance checks -> Production CSID),
 * invoice signing, reporting / clearance and certificate renewal.
 */
class ZatcaManager
{
    public function __construct(
        private CsrGenerator $csrGenerator,
        private InvoiceXmlBuilder $xmlBuilder,
        private InvoiceSigner $signer,
    ) {
    }

    public function api(ZatcaDevice $device): ZatcaApi
    {
        return new ZatcaApi($device->environment);
    }

    /*
    |--------------------------------------------------------------------------
    | Onboarding
    |--------------------------------------------------------------------------
    */

    /**
     * Run the complete onboarding of a device. The OTP may be omitted in the sandbox.
     *
     * @return array<string, array{status: string, http: int, errors: array, warnings: array}> compliance check results
     */
    public function onboard(ZatcaDevice $device, ?string $otp = null): array
    {
        $this->generateCsr($device);
        $this->requestComplianceCsid($device, $otp ?? $this->sandboxOtp($device));

        $results = $this->runComplianceChecks($device);
        $failed = array_filter($results, fn ($result) => $result['errors'] !== [] || $result['http'] >= 300);

        if ($failed) {
            $details = collect($failed)->map(fn ($r, $label) => "$label: ".implode('; ', $r['errors']))->implode(' | ');

            throw new ZatcaException("Compliance checks failed. $details");
        }

        $this->requestProductionCsid($device);

        return $results;
    }

    public function generateCsr(ZatcaDevice $device): void
    {
        if ($device->icv > 0) {
            throw new ZatcaException('This device has already issued invoices. Use renew() to get a new certificate.');
        }

        $result = $this->csrGenerator->generate($device);

        $device->update([
            'private_key' => $result['private_key'],
            'csr' => $result['csr'],
            'compliance_request_id' => null,
            'compliance_certificate' => null,
            'compliance_secret' => null,
            'compliance_checks' => null,
            'production_request_id' => null,
            'production_certificate' => null,
            'production_secret' => null,
            'production_expires_at' => null,
        ]);
    }

    public function requestComplianceCsid(ZatcaDevice $device, string $otp): void
    {
        if (! $device->hasCsr()) {
            throw new ZatcaException('Generate the CSR first.');
        }

        $response = $this->api($device)->complianceCsid($device->csr, $otp);
        ZatcaApi::ensureSuccessful($response, 'Compliance CSID');

        $device->update([
            'compliance_request_id' => $response->json('requestID'),
            'compliance_certificate' => $response->json('binarySecurityToken'),
            'compliance_secret' => $response->json('secret'),
            'compliance_checks' => null,
        ]);
    }

    /**
     * Sign and submit one sample of every document type the device is onboarded for.
     *
     * @return array<string, array{status: string, http: int, errors: array, warnings: array}>
     */
    public function runComplianceChecks(ZatcaDevice $device): array
    {
        if (! $device->hasComplianceCsid()) {
            throw new ZatcaException('Request the Compliance CSID first.');
        }

        $certificate = new Certificate(base64_decode($device->compliance_certificate));
        $previousHash = ZatcaDevice::INITIAL_PIH;
        $results = [];
        $icv = 0;

        foreach ($this->sampleInvoices($device) as $invoice) {
            $signed = $this->sign($invoice, $device, $certificate, ++$icv, $previousHash);

            $response = $this->api($device)->complianceInvoice(
                $device->compliance_certificate,
                $device->compliance_secret,
                $this->payload($invoice->uuid, $signed['hash'], $signed['xml']),
            );

            $results[$invoice->label()] = ZatcaApi::summarize($response);
            $previousHash = $signed['hash'];
        }

        $device->update(['compliance_checks' => $results]);

        return $results;
    }

    public function requestProductionCsid(ZatcaDevice $device): void
    {
        if (! $device->hasComplianceCsid()) {
            throw new ZatcaException('Request the Compliance CSID and pass the compliance checks first.');
        }

        $response = $this->api($device)->productionCsid(
            $device->compliance_certificate,
            $device->compliance_secret,
            $device->compliance_request_id,
        );
        ZatcaApi::ensureSuccessful($response, 'Production CSID');

        $this->storeProductionCsid($device, $response->json());
    }

    /**
     * Renew the Production CSID before it expires. A new key pair is generated;
     * the invoice chain (ICV / PIH) continues unchanged.
     */
    public function renew(ZatcaDevice $device, ?string $otp = null): void
    {
        if (! $device->hasProductionCsid()) {
            throw new ZatcaException('Only an onboarded device can be renewed.');
        }

        $result = $this->csrGenerator->generate($device);

        $response = $this->api($device)->renewProductionCsid(
            $device->production_certificate,
            $device->production_secret,
            $result['csr'],
            $otp ?? $this->sandboxOtp($device),
        );
        ZatcaApi::ensureSuccessful($response, 'Renew Production CSID');

        DB::transaction(function () use ($device, $result, $response) {
            $device->update(['private_key' => $result['private_key'], 'csr' => $result['csr']]);
            $this->storeProductionCsid($device, $response->json());
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Invoices
    |--------------------------------------------------------------------------
    */

    /**
     * Assign the next ICV / PIH of the device, sign the invoice and store it.
     *
     * @param  Model|null  $documentable  Your own invoice model, linked through HasZatcaDocuments
     */
    public function issue(ZatcaDevice $device, InvoiceData $invoice, ?Model $documentable = null): ZatcaDocument
    {
        return DB::transaction(function () use ($device, $invoice, $documentable) {
            // Lock the device row so two invoices never get the same ICV / PIH
            $device = ZatcaDevice::query()->lockForUpdate()->findOrFail($device->getKey());

            if (! $device->isReady()) {
                throw new ZatcaException('Complete ZATCA onboarding of this device before issuing invoices.');
            }

            if ($invoice->isSimplified() ? ! $device->issuesSimplified() : ! $device->issuesStandard()) {
                throw new ZatcaException("This device is not onboarded for {$invoice->type} invoices.");
            }

            $icv = $device->icv + 1;
            $previousHash = $device->previousInvoiceHash();
            $signed = $this->sign($invoice, $device, new Certificate($device->signingCertificate()), $icv, $previousHash);

            $document = new ZatcaDocument([
                'invoice_number' => $invoice->number,
                'uuid' => $invoice->uuid,
                'invoice_type' => $invoice->type,
                'document_type' => $invoice->documentType,
                'icv' => $icv,
                'previous_invoice_hash' => $previousHash,
                'invoice_hash' => $signed['hash'],
                'total_amount' => $invoice->totalAmount(),
                'vat_amount' => $invoice->vatAmount(),
                'signed_xml' => $signed['xml'],
                'qr_code' => $signed['qr'],
                'status' => ZatcaDocument::STATUS_PENDING,
            ]);
            $document->device()->associate($device);

            if ($documentable) {
                $document->documentable()->associate($documentable);
            }

            $document->save();

            $device->update(['icv' => $icv, 'previous_invoice_hash' => $signed['hash']]);

            return $document;
        });
    }

    /**
     * Report (simplified) or clear (standard) a signed document.
     *
     * @return array{status: string, http: int, errors: array, warnings: array}
     */
    public function submit(ZatcaDocument $document): array
    {
        if ($document->isAccepted()) {
            throw new ZatcaException("Document {$document->invoice_number} was already {$document->status}.");
        }

        $device = $document->device;
        $api = $this->api($device);
        $payload = $this->payload($document->uuid, $document->invoice_hash, $document->signed_xml);

        try {
            $response = $document->isSimplified()
                ? $api->report($device->production_certificate, $device->production_secret, $payload)
                : $api->clear($device->production_certificate, $device->production_secret, $payload);
        } catch (ConnectionException $e) {
            // Leave the document pending so it can be retried (zatca:submit-pending)
            throw new ZatcaException('Could not reach ZATCA: '.$e->getMessage(), 0, $e);
        }

        $summary = ZatcaApi::summarize($response);

        if ($cleared = $response->json('clearedInvoice')) {
            // The cleared invoice is stamped by ZATCA and carries ZATCA's QR code
            $document->cleared_xml = base64_decode($cleared);
            $document->qr_code = $this->extractQr($document->cleared_xml) ?? $document->qr_code;
        }

        $document->fill([
            'status' => $summary['status'],
            'response' => ['summary' => $summary, 'zatca' => $response->json() ?? ['body' => $response->body()]],
            'submitted_at' => now(),
        ])->save();

        return $summary;
    }

    /**
     * Issue and immediately submit an invoice.
     */
    public function issueAndSubmit(ZatcaDevice $device, InvoiceData $invoice, ?Model $documentable = null): ZatcaDocument
    {
        $document = $this->issue($device, $invoice, $documentable);
        $this->submit($document);

        return $document;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{xml: string, hash: string, signature: string, qr: string}
     */
    private function sign(InvoiceData $invoice, ZatcaDevice $device, Certificate $certificate, int $icv, string $previousHash): array
    {
        $xml = $this->xmlBuilder->build($invoice, $device, $icv, $previousHash);

        return $this->signer->sign($xml, $device->private_key, $certificate, [
            'seller_name' => $device->seller_name,
            'vat_number' => $device->vat_number,
            'timestamp' => $invoice->issueDate().'T'.$invoice->issueTime(),
            'total' => number_format($invoice->totalAmount(), 2, '.', ''),
            'vat_total' => number_format($invoice->vatAmount(), 2, '.', ''),
            'simplified' => $invoice->isSimplified(),
        ]);
    }

    private function storeProductionCsid(ZatcaDevice $device, array $json): void
    {
        $certificate = new Certificate(base64_decode($json['binarySecurityToken']));

        $device->update([
            'production_request_id' => $json['requestID'] ?? null,
            'production_certificate' => $json['binarySecurityToken'],
            'production_secret' => $json['secret'],
            'production_expires_at' => $certificate->validTo(),
        ]);
    }

    private function sandboxOtp(ZatcaDevice $device): string
    {
        if ($device->environment !== 'sandbox') {
            throw new ZatcaException('An OTP from the Fatoora portal is required outside the sandbox.');
        }

        return (string) config('zatca.sandbox_otp', '123345');
    }

    private function extractQr(string $xml): ?string
    {
        $pattern = '#<cbc:ID>QR</cbc:ID>\s*<cac:Attachment>\s*<cbc:EmbeddedDocumentBinaryObject[^>]*>([^<]+)<#';

        return preg_match($pattern, $xml, $matches) ? trim($matches[1]) : null;
    }

    private function payload(string $uuid, string $hash, string $xml): array
    {
        return [
            'invoiceHash' => $hash,
            'uuid' => $uuid,
            'invoice' => base64_encode($xml),
        ];
    }

    /**
     * One invoice, credit note and debit note for each invoice type the device issues.
     *
     * @return InvoiceData[]
     */
    private function sampleInvoices(ZatcaDevice $device): array
    {
        $types = array_filter([
            $device->issuesStandard() ? InvoiceData::STANDARD : null,
            $device->issuesSimplified() ? InvoiceData::SIMPLIFIED : null,
        ]);

        $buyer = new Buyer(
            name: 'Fatoora Samples LTD',
            vatNumber: '399999999800003',
            address: new Address('Salah Al-Din', '1111', 'Al-Murooj', 'Riyadh', '12222'),
        );

        $invoices = [];

        foreach ($types as $type) {
            foreach ([InvoiceData::INVOICE, InvoiceData::CREDIT_NOTE, InvoiceData::DEBIT_NOTE] as $documentType) {
                $isNote = $documentType !== InvoiceData::INVOICE;

                $invoices[] = new InvoiceData(
                    number: 'COMPLIANCE-'.(count($invoices) + 1),
                    type: $type,
                    lines: [new InvoiceLine('Sample item', 2, 50)],
                    buyer: $buyer,
                    documentType: $documentType,
                    originalInvoiceNumber: $isNote ? 'COMPLIANCE-1' : null,
                    noteReason: $isNote ? 'Returned items' : null,
                );
            }
        }

        return $invoices;
    }
}
