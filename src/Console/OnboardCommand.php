<?php

namespace delwarhossaindev\Zatca\Console;

use Illuminate\Console\Command;
use delwarhossaindev\Zatca\Exceptions\ZatcaException;
use delwarhossaindev\Zatca\Models\ZatcaDevice;
use delwarhossaindev\Zatca\ZatcaManager;

class OnboardCommand extends Command
{
    protected $signature = 'zatca:onboard
                            {device : ID of the zatca_devices row}
                            {--otp= : OTP from the Fatoora portal (not needed in the sandbox)}
                            {--renew : Renew the Production CSID of an onboarded device}';

    protected $description = 'Onboard a device with ZATCA (CSR, compliance CSID, compliance checks, production CSID)';

    public function handle(ZatcaManager $zatca): int
    {
        $device = ZatcaDevice::findOrFail($this->argument('device'));
        $otp = $this->option('otp');

        if ($device->environment !== 'sandbox' && ! $otp) {
            $otp = $this->ask('OTP from fatoora.zatca.gov.sa');
        }

        try {
            if ($this->option('renew')) {
                $zatca->renew($device, $otp);
                $this->info("Certificate renewed. Valid until {$device->production_expires_at}.");

                return self::SUCCESS;
            }

            $results = $zatca->onboard($device, $otp);
        } catch (ZatcaException $e) {
            $this->error($e->getMessage());

            if ($device->fresh()->compliance_checks) {
                $this->printChecks($device->fresh()->compliance_checks);
            }

            return self::FAILURE;
        }

        $this->printChecks($results);
        $this->info("Device #{$device->id} ({$device->environment}) is ready. Certificate valid until {$device->production_expires_at}.");

        return self::SUCCESS;
    }

    private function printChecks(array $results): void
    {
        $this->table(['Document', 'Status', 'Errors', 'Warnings'], collect($results)->map(fn ($r, $label) => [
            $label,
            $r['status'],
            implode("\n", $r['errors']) ?: '-',
            count($r['warnings']),
        ])->values()->all());
    }
}
