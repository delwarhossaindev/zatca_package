<?php

namespace delwarhossaindev\Zatca\Support;

use delwarhossaindev\Zatca\Exceptions\ZatcaException;
use delwarhossaindev\Zatca\Models\ZatcaDevice;

/**
 * Generates the secp256k1 private key and the CSR with ZATCA specific extensions.
 */
class CsrGenerator
{
    /**
     * @return array{private_key: string, csr: string}
     */
    public function generate(ZatcaDevice $device): array
    {
        $template = config("zatca.environments.{$device->environment}.certificate_template");

        if (! $template) {
            throw new ZatcaException("Unknown ZATCA environment \"{$device->environment}\".");
        }

        $configPath = $this->writeConfig($device, $template);

        try {
            $options = ['config' => $configPath, 'digest_alg' => 'sha256', 'req_extensions' => 'req_ext'];

            $key = openssl_pkey_new($options + [
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'secp256k1',
            ]);

            if ($key === false) {
                throw new ZatcaException('Unable to generate private key: '.$this->opensslErrors());
            }

            $dn = [
                'countryName' => 'SA',
                'organizationalUnitName' => $device->branch_name,
                'organizationName' => $device->seller_name,
                'commonName' => $device->egs_name,
            ];

            $csr = openssl_csr_new($dn, $key, $options);

            if ($csr === false) {
                throw new ZatcaException('Unable to generate CSR: '.$this->opensslErrors());
            }

            openssl_csr_export($csr, $csrPem);
            openssl_pkey_export($key, $privateKeyPem, null, $options);
        } finally {
            @unlink($configPath);
        }

        return ['private_key' => $privateKeyPem, 'csr' => $csrPem];
    }

    private function writeConfig(ZatcaDevice $device, string $template): string
    {
        $config = <<<CNF
        oid_section = OIDs

        [ OIDs ]
        certificateTemplateName = 1.3.6.1.4.1.311.20.2

        [ req ]
        prompt = no
        default_bits = 2048
        utf8 = yes
        default_md = sha256
        distinguished_name = dn
        req_extensions = req_ext

        [ dn ]

        [ req_ext ]
        certificateTemplateName = ASN1:PRINTABLESTRING:{$template}
        subjectAltName = dirName:alt_names

        [ alt_names ]
        SN = {$this->value($device->egs_serial)}
        UID = {$this->value($device->vat_number)}
        title = {$this->value($device->invoice_types)}
        registeredAddress = {$this->value($device->street.' '.$device->city)}
        businessCategory = {$this->value($device->industry)}

        CNF;

        $path = tempnam(sys_get_temp_dir(), 'zatca_csr_');
        file_put_contents($path, $config);

        return $path;
    }

    private function value(string $value): string
    {
        // Keep values on a single line and escape characters with special meaning in openssl.cnf
        return addcslashes(preg_replace('/[\r\n]+/', ' ', trim($value)), '"#$\\;');
    }

    private function opensslErrors(): string
    {
        $errors = [];

        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }

        return implode('; ', $errors);
    }
}
