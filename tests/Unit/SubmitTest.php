<?php

namespace delwarhossaindev\Zatca\Tests\Unit;

use delwarhossaindev\Zatca\Exceptions\ZatcaException;
use delwarhossaindev\Zatca\Facades\Zatca;
use delwarhossaindev\Zatca\Models\ZatcaDevice;
use delwarhossaindev\Zatca\Models\ZatcaDocument;
use delwarhossaindev\Zatca\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class SubmitTest extends TestCase
{
    public function test_server_errors_and_rate_limits_keep_the_document_pending(): void
    {
        Http::fakeSequence()
            ->push('Service Unavailable', 500)
            ->push('Service Unavailable', 503)
            ->push('Too Many Requests', 429);

        foreach ([500, 503, 429] as $status) {
            $document = $this->makeDocument($this->onboardedDevice(), "INV-$status");

            try {
                Zatca::submit($document);
                $this->fail("HTTP $status should throw.");
            } catch (ZatcaException $e) {
                $this->assertStringContainsString('stays pending', $e->getMessage());
            }

            $this->assertSame(ZatcaDocument::STATUS_PENDING, $document->fresh()->status);
            $this->assertSame($status, $document->fresh()->response['summary']['http']);
        }
    }

    public function test_a_rejected_document_gets_zatca_status(): void
    {
        Http::fake(['*' => Http::response([
            'reportingStatus' => 'NOT_REPORTED',
            'validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'BR-KSA-01', 'message' => 'Bad']]],
        ], 400)]);
        $document = $this->makeDocument($this->onboardedDevice(), 'INV-1');

        $summary = Zatca::submit($document);

        $this->assertSame('NOT_REPORTED', $summary['status']);
        $this->assertSame('NOT_REPORTED', $document->fresh()->status);
        $this->assertSame(['BR-KSA-01: Bad'], $document->fresh()->errors());
    }

    public function test_a_stale_copy_of_an_accepted_document_is_not_sent_again(): void
    {
        Http::fake();
        $document = $this->makeDocument($this->onboardedDevice(), 'INV-1');

        // Another process reported it after this copy was loaded
        ZatcaDocument::whereKey($document->id)->update(['status' => 'REPORTED']);

        try {
            Zatca::submit($document);
            $this->fail('An accepted document must not be submitted again.');
        } catch (ZatcaException) {
            Http::assertNothingSent();
            $this->assertSame('REPORTED', $document->fresh()->status);
        }
    }

    public function test_a_device_with_an_expired_certificate_can_be_onboarded_again(): void
    {
        $device = $this->onboardedDevice(['icv' => 5, 'previous_invoice_hash' => 'previous-hash', 'production_expires_at' => now()->subDay()]);

        Zatca::generateCsr($device);

        $device->refresh();
        $this->assertTrue($device->hasCsr());
        $this->assertFalse($device->hasProductionCsid());
        $this->assertSame(5, $device->icv);
        $this->assertSame('previous-hash', $device->previous_invoice_hash);
    }

    public function test_a_device_with_a_valid_certificate_must_renew(): void
    {
        $device = $this->onboardedDevice(['icv' => 5, 'production_expires_at' => now()->addYear()]);

        $this->expectException(ZatcaException::class);
        Zatca::generateCsr($device);
    }

    private function onboardedDevice(array $attributes = []): ZatcaDevice
    {
        $device = $this->makeDevice();
        $device->update($attributes + [
            'production_certificate' => 'token',
            'production_secret' => 'secret',
            'production_expires_at' => now()->addYear(),
        ]);

        return $device;
    }
}
