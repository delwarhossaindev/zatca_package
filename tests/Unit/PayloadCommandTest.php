<?php

namespace delwarhossaindev\Zatca\Tests\Unit;

use delwarhossaindev\Zatca\Tests\TestCase;

class PayloadCommandTest extends TestCase
{
    public function test_prints_the_request_body_of_a_document(): void
    {
        $document = $this->makeDocument($this->makeDevice(), 'INV-1');

        $this->artisan('zatca:payload', ['document' => 'INV-1'])
            ->expectsOutput($document->invoice_hash)
            ->expectsOutput($document->uuid)
            ->expectsOutput(base64_encode('<Invoice/>'))
            ->assertSuccessful();

        // By UUID and by ID as well
        $this->artisan('zatca:payload', ['document' => $document->uuid])->assertSuccessful();
        $this->artisan('zatca:payload', ['document' => (string) $document->id])->assertSuccessful();
    }

    public function test_same_invoice_number_on_two_devices_needs_the_device(): void
    {
        $this->makeDocument($this->makeDevice(), 'INV-1');
        $second = $this->makeDocument($this->makeDevice(), 'INV-1');

        $this->artisan('zatca:payload', ['document' => 'INV-1'])->assertFailed();

        $this->artisan('zatca:payload', ['document' => 'INV-1', '--device' => $second->zatca_device_id])
            ->expectsOutput($second->uuid)
            ->assertSuccessful();
    }

    public function test_prints_the_csr_and_credentials_of_a_device(): void
    {
        $device = $this->makeDevice();
        $device->update([
            'csr' => "-----BEGIN CERTIFICATE REQUEST-----\nMIIB\n-----END CERTIFICATE REQUEST-----\n",
            'compliance_certificate' => 'compliance-token',
            'compliance_secret' => 'compliance-secret',
        ]);

        $this->artisan('zatca:payload', ['--device' => $device->id])
            ->expectsOutput(base64_encode($device->csr))
            ->expectsOutput('compliance-token')
            ->expectsOutput('compliance-secret')
            ->assertSuccessful();
    }

    public function test_unknown_document_or_missing_argument_fails(): void
    {
        $this->artisan('zatca:payload', ['document' => 'NOPE'])->assertFailed();
        $this->artisan('zatca:payload')->assertFailed();
        $this->artisan('zatca:payload', ['--device' => 999])->assertFailed();
    }
}
