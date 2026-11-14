<?php

namespace delwarhossaindev\Zatca\Tests;

use delwarhossaindev\Zatca\Models\ZatcaDevice;
use delwarhossaindev\Zatca\Models\ZatcaDocument;
use delwarhossaindev\Zatca\ZatcaServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [ZatcaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('zatca.env', 'sandbox');
    }

    protected function makeDevice(array $attributes = []): ZatcaDevice
    {
        return ZatcaDevice::create($attributes + [
            'seller_name' => 'Al Noor Trading Co',
            'vat_number' => '399999999900003',
            'crn' => '1010010000',
            'street' => 'Prince Sultan',
            'building_number' => '2322',
            'district' => 'Al-Murabba',
            'city' => 'Riyadh',
            'postal_code' => '23333',
            'egs_name' => 'POS-01',
            'egs_serial' => '1-NexaTech|2-Inventory|3-'.fake()->uuid(),
            'branch_name' => 'Riyadh Branch',
            'industry' => 'Supply activities',
            'invoice_types' => '1100',
        ]);
    }

    protected function makeDocument(ZatcaDevice $device, string $number, array $attributes = []): ZatcaDocument
    {
        $document = new ZatcaDocument($attributes + [
            'invoice_number' => $number,
            'uuid' => fake()->uuid(),
            'invoice_type' => 'simplified',
            'document_type' => 'invoice',
            'icv' => 1,
            'previous_invoice_hash' => ZatcaDevice::INITIAL_PIH,
            'invoice_hash' => base64_encode(random_bytes(32)),
            'total_amount' => 115,
            'vat_amount' => 15,
            'signed_xml' => '<Invoice/>',
            'qr_code' => 'qr',
        ]);
        $document->device()->associate($device)->save();

        return $document;
    }
}
