<?php

namespace delwarhossaindev\Zatca\Console;

use Illuminate\Console\Command;
use delwarhossaindev\Zatca\Exceptions\ZatcaException;
use delwarhossaindev\Zatca\Models\ZatcaDocument;
use delwarhossaindev\Zatca\ZatcaManager;

/**
 * Sends documents that never reached ZATCA. Schedule it so simplified invoices
 * are always reported within 24 hours.
 */
class SubmitPendingCommand extends Command
{
    protected $signature = 'zatca:submit-pending {--device= : Only this device}';

    protected $description = 'Report / clear signed documents that have not reached ZATCA yet';

    public function handle(ZatcaManager $zatca): int
    {
        $documents = ZatcaDocument::query()
            ->pending()
            ->when($this->option('device'), fn ($q, $device) => $q->where('zatca_device_id', $device))
            ->orderBy('zatca_device_id')
            ->orderBy('icv')
            ->get();

        $failed = 0;

        foreach ($documents as $document) {
            try {
                $summary = $zatca->submit($document);
                $this->line("{$document->invoice_number}: {$summary['status']}");
            } catch (ZatcaException $e) {
                $failed++;
                $this->error("{$document->invoice_number}: {$e->getMessage()}");
            }
        }

        $this->info("Submitted {$documents->count()} document(s), $failed failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
