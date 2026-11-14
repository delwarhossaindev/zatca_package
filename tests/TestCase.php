<?php

namespace delwarhossaindev\Zatca\Tests;

use delwarhossaindev\Zatca\Models\ZatcaDevice;
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
}
