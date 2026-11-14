<?php

namespace delwarhossaindev\Zatca\Data;

/**
 * Saudi national address.
 */
final class Address
{
    public function __construct(
        public readonly string $street,
        public readonly string $buildingNumber,
        public readonly string $district,
        public readonly string $city,
        public readonly string $postalCode,
        public readonly ?string $plotIdentification = null,
        public readonly string $countryCode = 'SA',
    ) {
    }
}
