<?php

namespace delwarhossaindev\Zatca\Console;

use Illuminate\Console\Command;
use delwarhossaindev\Zatca\Models\ZatcaDevice;

class StatusCommand extends Command
{
    protected $signature = 'zatca:status';

    protected $description = 'Show the onboarding state, certificate expiry and pending documents of every device';

    public function handle(): int
    {
        $devices = ZatcaDevice::query()->withCount(['documents as pending_count' => fn ($q) => $q->pending()])->get();

        $this->table(['ID', 'Device', 'Seller', 'Env', 'Ready', 'Certificate expires', 'Last ICV', 'Pending'], $devices->map(fn ($d) => [
            $d->id,
            $d->egs_name,
            $d->seller_name,
            $d->environment,
            $d->isReady() ? 'yes' : 'no',
            $d->production_expires_at ? $d->production_expires_at->toDateString().' ('.$d->production_expires_at->diffForHumans().')' : '-',
            $d->icv,
            $d->pending_count,
        ])->all());

        return self::SUCCESS;
    }
}
