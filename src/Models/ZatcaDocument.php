<?php

namespace delwarhossaindev\Zatca\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A signed e-invoice and ZATCA's answer to it.
 */
class ZatcaDocument extends Model
{
    public const STATUS_PENDING = 'pending';

    /**
     * Statuses after which the document must not be submitted again.
     */
    public const ACCEPTED_STATUSES = ['REPORTED', 'CLEARED'];

    protected $guarded = ['id'];

    protected $casts = [
        'response' => 'array',
        'submitted_at' => 'datetime',
        'icv' => 'integer',
        'total_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(ZatcaDevice::class, 'zatca_device_id');
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isSimplified(): bool
    {
        return $this->invoice_type === 'simplified';
    }

    public function isAccepted(): bool
    {
        return in_array($this->status, self::ACCEPTED_STATUSES, true);
    }

    /**
     * The XML to hand to the buyer: ZATCA's stamped copy for cleared invoices.
     */
    public function finalXml(): string
    {
        return $this->cleared_xml ?: $this->signed_xml;
    }

    public function errors(): array
    {
        return $this->response['summary']['errors'] ?? [];
    }

    public function warnings(): array
    {
        return $this->response['summary']['warnings'] ?? [];
    }

    /**
     * Signed documents that have not reached ZATCA yet (never sent or connection failed).
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
