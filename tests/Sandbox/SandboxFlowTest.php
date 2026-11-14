<?php

namespace delwarhossaindev\Zatca\Tests\Sandbox;

use delwarhossaindev\Zatca\Data\Address;
use delwarhossaindev\Zatca\Data\Buyer;
use delwarhossaindev\Zatca\Data\InvoiceData;
use delwarhossaindev\Zatca\Data\InvoiceLine;
use delwarhossaindev\Zatca\Exceptions\ZatcaException;
use delwarhossaindev\Zatca\Facades\Zatca;
use delwarhossaindev\Zatca\Models\ZatcaDocument;
use delwarhossaindev\Zatca\Tests\TestCase;

/**
 * Talks to the real ZATCA developer portal (sandbox). Run with: vendor/bin/phpunit --testsuite Sandbox
 */
class SandboxFlowTest extends TestCase
{
    public function test_onboarding_and_invoices_against_the_sandbox(): void
    {
        $device = $this->makeDevice();

        // Onboarding: CSR, compliance CSID, 6 compliance checks, production CSID
        $this->artisan('zatca:onboard', ['device' => $device->id])->assertSuccessful();
        $device->refresh();

        $this->assertTrue($device->isReady());
        $this->assertCount(6, $device->compliance_checks);
        $this->assertNotNull($device->production_expires_at);

        $buyer = new Buyer(
            name: 'Fatoora Samples LTD',
            vatNumber: '399999999800003',
            address: new Address('Salah Al-Din', '1111', 'Al-Murooj', 'Riyadh', '12222'),
        );

        // Simplified invoice with a zero rated line -> reported
        $simplified = Zatca::issueAndSubmit($device, new InvoiceData(
            number: 'INV-1',
            type: InvoiceData::SIMPLIFIED,
            lines: [
                new InvoiceLine('Rice 5kg', 2, 45.5),
                new InvoiceLine('Medicine', 1, 30, InvoiceLine::ZERO_RATED, 0, 'VATEX-SA-35', 'Medicines and medical equipment'),
            ],
        ));
        $this->assertAccepted($simplified, 'REPORTED');

        // Standard invoice -> cleared, ZATCA returns the stamped XML
        $standard = Zatca::issueAndSubmit($device, new InvoiceData(
            number: 'INV-2',
            type: InvoiceData::STANDARD,
            lines: [new InvoiceLine('Laptop', 1, 2500)],
            buyer: $buyer,
            paymentMeans: '30',
        ));
        $this->assertAccepted($standard, 'CLEARED');
        $this->assertNotNull($standard->cleared_xml);

        // Credit note for the simplified invoice, issued now and sent by the pending command
        $credit = Zatca::issue($device, new InvoiceData(
            number: 'CRN-1',
            type: InvoiceData::SIMPLIFIED,
            lines: [new InvoiceLine('Rice 5kg', 1, 45.5)],
            documentType: InvoiceData::CREDIT_NOTE,
            originalInvoiceNumber: 'INV-1',
            noteReason: 'Returned items',
        ));
        $this->assertSame('pending', $credit->status);

        $this->artisan('zatca:submit-pending')->assertSuccessful();
        $this->assertAccepted($credit->fresh(), 'REPORTED');

        // The chain continues: ICV 1..3, each PIH is the previous hash
        $this->assertSame([1, 2, 3], ZatcaDocument::orderBy('icv')->pluck('icv')->all());
        $this->assertSame($simplified->invoice_hash, $standard->previous_invoice_hash);
        $this->assertSame($standard->invoice_hash, $credit->previous_invoice_hash);
        $this->assertSame(3, $device->fresh()->icv);

        // An accepted document cannot be sent twice
        $this->expectException(ZatcaException::class);
        Zatca::submit($simplified->fresh());
    }

    public function test_renewed_certificate_keeps_the_invoice_chain(): void
    {
        $device = $this->makeDevice(['invoice_types' => '0100']);
        Zatca::onboard($device);

        $first = Zatca::issueAndSubmit($device, new InvoiceData('INV-1', InvoiceData::SIMPLIFIED, [new InvoiceLine('Tea', 1, 10)]));
        $oldCertificate = $device->fresh()->production_certificate;

        $this->artisan('zatca:onboard', ['device' => $device->id, '--renew' => true])->assertSuccessful();
        $this->assertNotSame($oldCertificate, $device->fresh()->production_certificate);

        $second = Zatca::issueAndSubmit($device->fresh(), new InvoiceData('INV-2', InvoiceData::SIMPLIFIED, [new InvoiceLine('Tea', 1, 10)]));

        $this->assertAccepted($first, 'REPORTED');
        $this->assertAccepted($second, 'REPORTED');
        $this->assertSame(2, $second->icv);
        $this->assertSame($first->invoice_hash, $second->previous_invoice_hash);
    }

    private function assertAccepted(ZatcaDocument $document, string $status): void
    {
        $this->assertSame($status, $document->status, json_encode($document->response['summary'] ?? null));
        $this->assertSame([], $document->errors());
    }
}
