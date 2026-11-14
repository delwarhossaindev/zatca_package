<?php

namespace delwarhossaindev\Zatca\Data;

/**
 * The customer. Required (with address) for standard invoices, optional for simplified ones.
 */
final class Buyer
{
    /**
     * @param  string|null  $identification  Other buyer ID when there is no VAT number
     * @param  string|null  $identificationScheme  TIN, CRN, MOM, MLS, 700, SAG, NAT, GCC, IQA, PAS or OTH
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $vatNumber = null,
        public readonly ?Address $address = null,
        public readonly ?string $identification = null,
        public readonly ?string $identificationScheme = null,
    ) {
    }
}
