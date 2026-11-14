<?php

namespace delwarhossaindev\Zatca\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An EGS unit (device / POS / branch) onboarded with ZATCA.
 */
class ZatcaDevice extends Model
{
    /**
     * Base64 SHA-256 of "0", used as the PIH of the very first invoice.
     */
    public const INITIAL_PIH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    protected $guarded = ['id'];

    protected $casts = [
        'private_key' => 'encrypted',
        'compliance_secret' => 'encrypted',
        'production_secret' => 'encrypted',
        'compliance_checks' => 'array',
        'production_expires_at' => 'datetime',
        'icv' => 'integer',
    ];

    protected $hidden = ['private_key', 'compliance_secret', 'production_secret'];

    protected static function booted(): void
    {
        static::creating(function (self $device) {
            $device->environment ??= config('zatca.env', 'sandbox');
        });
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ZatcaDocument::class);
    }

    public function hasCsr(): bool
    {
        return filled($this->private_key) && filled($this->csr);
    }

    public function hasComplianceCsid(): bool
    {
        return filled($this->compliance_certificate) && filled($this->compliance_secret);
    }

    public function hasProductionCsid(): bool
    {
        return filled($this->production_certificate) && filled($this->production_secret);
    }

    /**
     * Onboarding is complete and invoices can be issued and submitted.
     */
    public function isReady(): bool
    {
        return $this->hasCsr() && $this->hasProductionCsid();
    }

    public function previousInvoiceHash(): string
    {
        return $this->previous_invoice_hash ?: self::INITIAL_PIH;
    }

    /**
     * Base64 encoded DER certificate (the decoded binarySecurityToken).
     */
    public function signingCertificate(): ?string
    {
        $token = $this->hasProductionCsid() ? $this->production_certificate : $this->compliance_certificate;

        return $token ? base64_decode($token) : null;
    }

    public function issuesStandard(): bool
    {
        return ($this->invoice_types[0] ?? '0') === '1';
    }

    public function issuesSimplified(): bool
    {
        return ($this->invoice_types[1] ?? '0') === '1';
    }
}
