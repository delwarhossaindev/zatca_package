<?php

namespace delwarhossaindev\Zatca\Facades;

use Illuminate\Support\Facades\Facade;
use delwarhossaindev\Zatca\ZatcaManager;

/**
 * @method static array onboard(\delwarhossaindev\Zatca\Models\ZatcaDevice $device, ?string $otp = null)
 * @method static void generateCsr(\delwarhossaindev\Zatca\Models\ZatcaDevice $device)
 * @method static void requestComplianceCsid(\delwarhossaindev\Zatca\Models\ZatcaDevice $device, string $otp)
 * @method static array runComplianceChecks(\delwarhossaindev\Zatca\Models\ZatcaDevice $device)
 * @method static void requestProductionCsid(\delwarhossaindev\Zatca\Models\ZatcaDevice $device)
 * @method static void renew(\delwarhossaindev\Zatca\Models\ZatcaDevice $device, ?string $otp = null)
 * @method static \delwarhossaindev\Zatca\Models\ZatcaDocument issue(\delwarhossaindev\Zatca\Models\ZatcaDevice $device, \delwarhossaindev\Zatca\Data\InvoiceData $invoice, ?\Illuminate\Database\Eloquent\Model $documentable = null)
 * @method static array submit(\delwarhossaindev\Zatca\Models\ZatcaDocument $document)
 * @method static \delwarhossaindev\Zatca\Models\ZatcaDocument issueAndSubmit(\delwarhossaindev\Zatca\Models\ZatcaDevice $device, \delwarhossaindev\Zatca\Data\InvoiceData $invoice, ?\Illuminate\Database\Eloquent\Model $documentable = null)
 * @method static \delwarhossaindev\Zatca\Support\ZatcaApi api(\delwarhossaindev\Zatca\Models\ZatcaDevice $device)
 *
 * @see ZatcaManager
 */
class Zatca extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ZatcaManager::class;
    }
}
