<?php

namespace delwarhossaindev\Zatca\Console;

use Illuminate\Console\Command;
use delwarhossaindev\Zatca\Models\ZatcaDevice;
use delwarhossaindev\Zatca\Models\ZatcaDocument;

/**
 * Prints the values the Postman collection needs: the request body of a signed
 * document, or the CSR and CSID credentials of a device.
 */
class PayloadCommand extends Command
{
    protected $signature = 'zatca:payload
                            {document? : Invoice number, UUID or ID of a zatca_documents row}
                            {--device= : ID of the zatca_devices row (alone: print its CSR and credentials)}
                            {--json : Print JSON instead of a table}';

    protected $description = 'Print the invoiceHash / uuid / invoice of a document, or the CSR and credentials of a device, for Postman';

    public function handle(): int
    {
        if ($this->argument('document') === null) {
            if (! $this->option('device')) {
                $this->error('Pass a document, or --device= for the CSR and credentials.');

                return self::FAILURE;
            }

            $device = ZatcaDevice::find($this->option('device'));

            if (! $device) {
                $this->error("Device #{$this->option('device')} not found.");

                return self::FAILURE;
            }

            $this->warn('These are secret credentials. Do not commit or share them.');

            return $this->output([
                'csr' => $device->csr ? base64_encode($device->csr) : null,
                'complianceToken' => $device->compliance_certificate,
                'complianceSecret' => $device->compliance_secret,
                'complianceRequestId' => $device->compliance_request_id,
                'productionToken' => $device->production_certificate,
                'productionSecret' => $device->production_secret,
                'productionRequestId' => $device->production_request_id,
            ]);
        }

        $document = $this->findDocument($this->argument('document'));

        if (! $document) {
            return self::FAILURE;
        }

        // ZATCA expects the XML the EGS signed, also for clearance (not the cleared copy)
        return $this->output([
            'invoiceHash' => $document->invoice_hash,
            'uuid' => $document->uuid,
            'invoice' => base64_encode($document->signed_xml),
        ]);
    }

    private function findDocument(string $value): ?ZatcaDocument
    {
        $matches = ZatcaDocument::query()
            ->when($this->option('device'), fn ($q, $device) => $q->where('zatca_device_id', $device))
            ->where(fn ($q) => $q->where('invoice_number', $value)->orWhere('uuid', $value))
            ->get();

        if ($matches->isEmpty() && ctype_digit($value)) {
            $matches = ZatcaDocument::query()
                ->when($this->option('device'), fn ($q, $device) => $q->where('zatca_device_id', $device))
                ->whereKey($value)
                ->get();
        }

        if ($matches->isEmpty()) {
            $this->error("Document \"$value\" not found.");

            return null;
        }

        if ($matches->count() > 1) {
            $this->error("Several devices have document \"$value\". Add --device=.");

            return null;
        }

        return $matches->first();
    }

    private function output(array $values): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($values as $key => $value) {
                $this->line("<info>$key</info>");
                $this->line($value ?? '-');
                $this->newLine();
            }
        }

        return self::SUCCESS;
    }
}
