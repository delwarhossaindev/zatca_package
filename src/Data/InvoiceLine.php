<?php

namespace delwarhossaindev\Zatca\Data;

use delwarhossaindev\Zatca\Exceptions\ZatcaException;

final class InvoiceLine
{
    public const STANDARD = 'S';
    public const ZERO_RATED = 'Z';
    public const EXEMPT = 'E';
    public const OUT_OF_SCOPE = 'O';

    /**
     * @param  string  $vatCategory  S (standard), Z (zero rated), E (exempt) or O (out of scope)
     * @param  string|null  $exemptionReasonCode  Required for Z, E and O, e.g. "VATEX-SA-32"
     * @param  string|null  $exemptionReason  Text of the exemption reason
     */
    public function __construct(
        public readonly string $name,
        public readonly float $quantity,
        public readonly float $unitPrice,
        public readonly string $vatCategory = self::STANDARD,
        public readonly float $vatPercent = 15.0,
        public readonly ?string $exemptionReasonCode = null,
        public readonly ?string $exemptionReason = null,
        public readonly string $unitCode = 'PCE',
    ) {
        if (! in_array($vatCategory, [self::STANDARD, self::ZERO_RATED, self::EXEMPT, self::OUT_OF_SCOPE], true)) {
            throw new ZatcaException("Unknown VAT category \"$vatCategory\".");
        }

        if ($vatCategory !== self::STANDARD && ($vatPercent != 0.0 || ! $exemptionReasonCode || ! $exemptionReason)) {
            throw new ZatcaException("VAT category $vatCategory needs 0% VAT and an exemption reason code and text.");
        }

        if ($quantity <= 0 || $unitPrice < 0) {
            throw new ZatcaException("Line \"$name\" needs a positive quantity and a non-negative price.");
        }
    }

    public function lineExtensionAmount(): float
    {
        return round($this->quantity * $this->unitPrice, 2);
    }

    public function vatAmount(): float
    {
        return round($this->lineExtensionAmount() * $this->vatPercent / 100, 2);
    }
}
