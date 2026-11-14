<?php

namespace delwarhossaindev\Zatca\Tests\Unit;

use delwarhossaindev\Zatca\Data\Address;
use delwarhossaindev\Zatca\Data\Buyer;
use delwarhossaindev\Zatca\Data\InvoiceData;
use delwarhossaindev\Zatca\Data\InvoiceLine;
use delwarhossaindev\Zatca\Exceptions\ZatcaException;
use delwarhossaindev\Zatca\Support\Certificate;
use delwarhossaindev\Zatca\Support\CsrGenerator;
use delwarhossaindev\Zatca\Support\InvoiceSigner;
use delwarhossaindev\Zatca\Support\InvoiceXmlBuilder;
use delwarhossaindev\Zatca\Support\QrCode;
use delwarhossaindev\Zatca\Tests\TestCase;

class InvoiceTest extends TestCase
{
    public function test_totals_are_calculated_per_vat_category(): void
    {
        $invoice = new InvoiceData(
            number: 'INV-1',
            type: InvoiceData::SIMPLIFIED,
            lines: [
                new InvoiceLine('Pen', 3, 3.33),
                new InvoiceLine('Book', 1, 10.01),
                new InvoiceLine('Medicine', 2, 25, InvoiceLine::ZERO_RATED, 0, 'VATEX-SA-35', 'Medicines and medical equipment'),
            ],
        );

        $this->assertSame(70.0, $invoice->lineExtensionAmount());
        $this->assertSame(3.0, $invoice->vatAmount());      // 15% of the 20.00 standard rated part only
        $this->assertSame(73.0, $invoice->totalAmount());
        $this->assertCount(2, $invoice->taxSubtotals());
    }

    public function test_standard_invoice_requires_buyer_address(): void
    {
        $this->expectException(ZatcaException::class);

        new InvoiceData('INV-1', InvoiceData::STANDARD, [new InvoiceLine('Pen', 1, 10)], new Buyer('ACME'));
    }

    public function test_notes_require_original_invoice_and_reason(): void
    {
        $this->expectException(ZatcaException::class);

        new InvoiceData('CRN-1', InvoiceData::SIMPLIFIED, [new InvoiceLine('Pen', 1, 10)], documentType: InvoiceData::CREDIT_NOTE);
    }

    public function test_exempt_lines_require_a_reason(): void
    {
        $this->expectException(ZatcaException::class);

        new InvoiceLine('Medicine', 1, 10, InvoiceLine::ZERO_RATED, 0);
    }

    public function test_standard_invoice_requires_buyer_vat_number_or_identification(): void
    {
        $address = new Address('Main', '1111', 'Al-Murooj', 'Riyadh', '12222');

        new InvoiceData('INV-1', InvoiceData::STANDARD, [new InvoiceLine('Pen', 1, 10)], new Buyer('ACME', identification: '1010010000', identificationScheme: 'CRN', address: $address));

        $this->expectException(ZatcaException::class);
        new InvoiceData('INV-2', InvoiceData::STANDARD, [new InvoiceLine('Pen', 1, 10)], new Buyer('ACME', address: $address));
    }

    public function test_only_sar_is_supported(): void
    {
        $this->expectException(ZatcaException::class);

        new InvoiceData('INV-1', InvoiceData::SIMPLIFIED, [new InvoiceLine('Pen', 1, 10)], currency: 'USD');
    }

    public function test_qr_values_longer_than_255_bytes_are_refused(): void
    {
        QrCode::encode([1 => str_repeat('a', 255)]);

        $this->expectException(ZatcaException::class);
        QrCode::encode([1 => str_repeat('ب', 128)]); // 256 bytes in UTF-8
    }

    public function test_qr_code_round_trip(): void
    {
        $qr = QrCode::encode([1 => 'Al Noor', 2 => '399999999900003', 3 => '2026-11-14T10:00:00', 4 => '115.00', 5 => '15.00']);

        $this->assertSame('Al Noor', QrCode::decode($qr)[1]);
        $this->assertSame('15.00', QrCode::decode($qr)[5]);
    }

    public function test_invoice_hash_is_the_same_before_and_after_signing(): void
    {
        $device = $this->makeDevice();
        $device->update(app(CsrGenerator::class)->generate($device));
        $certificate = $this->selfSignedCertificate($device->csr, $device->private_key);

        $invoice = new InvoiceData(
            number: 'INV-1',
            type: InvoiceData::STANDARD,
            lines: [new InvoiceLine('Pen & paper <A4>', 2, 50)],
            buyer: new Buyer('ACME', '399999999800003', new Address('Main', '1111', 'Al-Murooj', 'Riyadh', '12222')),
        );

        $signer = new InvoiceSigner();
        $xml = (new InvoiceXmlBuilder())->build($invoice, $device, 1, $device->previousInvoiceHash());
        $signed = $signer->sign($xml, $device->private_key, $certificate, [
            'seller_name' => $device->seller_name,
            'vat_number' => $device->vat_number,
            'timestamp' => '2026-11-14T10:00:00',
            'total' => '115.00',
            'vat_total' => '15.00',
            'simplified' => false,
        ]);

        $this->assertSame($signed['hash'], $signer->hash($signed['xml']));
        $this->assertStringContainsString('Pen &amp; paper &lt;A4&gt;', $signed['xml']);
        $this->assertStringNotContainsString('{{', $signed['xml']);

        // The signature verifies with the certificate's public key
        $this->assertSame(1, openssl_verify(base64_decode($signed['hash']), base64_decode($signed['signature']), $certificate->pem(), OPENSSL_ALGO_SHA256));
        $this->assertArrayNotHasKey(9, QrCode::decode($signed['qr']));
    }

    private function selfSignedCertificate(string $csr, string $privateKey): Certificate
    {
        $config = tempnam(sys_get_temp_dir(), 'zatca_test_');
        file_put_contents($config, "[ req ]\ndistinguished_name = dn\n[ dn ]\n");

        try {
            $x509 = openssl_csr_sign($csr, null, openssl_pkey_get_private($privateKey), 365, ['digest_alg' => 'sha256', 'config' => $config]);
            openssl_x509_export($x509, $pem);
        } finally {
            unlink($config);
        }

        return new Certificate(preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem));
    }
}
