<?php

namespace delwarhossaindev\Zatca\Support;

/**
 * Builds the TLV encoded, base64 QR code payload.
 *
 * Phase 1 uses tags 1-5. Phase 2 adds the invoice hash, signature, public key
 * and (for simplified invoices) the certificate signature as tags 6-9.
 */
class QrCode
{
    public static function encode(array $tags): string
    {
        $tlv = '';

        foreach ($tags as $tag => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // ZATCA uses a single byte for the tag and the length
            $tlv .= chr($tag).chr(strlen($value)).$value;
        }

        return base64_encode($tlv);
    }

    /**
     * @return array<int, string>
     */
    public static function decode(string $base64): array
    {
        $tlv = base64_decode($base64);
        $tags = [];
        $offset = 0;

        while ($offset < strlen($tlv)) {
            $tag = ord($tlv[$offset]);
            $length = ord($tlv[$offset + 1]);
            $offset += 2;

            $tags[$tag] = substr($tlv, $offset, $length);
            $offset += $length;
        }

        return $tags;
    }
}
