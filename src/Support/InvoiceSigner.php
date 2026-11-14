<?php

namespace delwarhossaindev\Zatca\Support;

use DOMDocument;
use DOMXPath;
use delwarhossaindev\Zatca\Exceptions\ZatcaException;

/**
 * Hashes and signs an invoice XML (XAdES enveloped signature) and generates the Phase 2 QR code.
 */
class InvoiceSigner
{
    /**
     * @param  string  $xml  Unsigned XML produced by InvoiceXmlBuilder
     * @param  array  $qrData  Phase 1 QR values: seller_name, vat_number, timestamp, total, vat_total, simplified
     * @return array{xml: string, hash: string, signature: string, qr: string}
     */
    public function sign(string $xml, string $privateKey, Certificate $certificate, array $qrData): array
    {
        $xml = str_replace(InvoiceXmlBuilder::PLACEHOLDER_UBL_EXTENSIONS, $this->ublExtensions(), $xml);

        $hash = $this->hash($xml);
        $signature = $this->signHash($hash, $privateKey);
        $signingTime = gmdate('Y-m-d\TH:i:s');

        $signedPropertiesHash = base64_encode(hash('sha256', $this->signedProperties($signingTime, $certificate, true)));

        $qr = QrCode::encode([
            1 => $qrData['seller_name'],
            2 => $qrData['vat_number'],
            3 => $qrData['timestamp'],
            4 => $qrData['total'],
            5 => $qrData['vat_total'],
            6 => $hash,
            7 => $signature,
            8 => $certificate->publicKey(),
            9 => $qrData['simplified'] ? $certificate->signature() : null,
        ]);

        $xml = strtr($xml, [
            '{{INVOICE_HASH}}' => $hash,
            '{{SIGNED_PROPERTIES_HASH}}' => $signedPropertiesHash,
            '{{SIGNATURE}}' => $signature,
            '{{CERTIFICATE}}' => $certificate->base64(),
            '{{SIGNED_PROPERTIES}}' => $this->signedProperties($signingTime, $certificate, false),
            InvoiceXmlBuilder::PLACEHOLDER_QR => $qr,
        ]);

        return compact('xml', 'hash', 'signature', 'qr');
    }

    /**
     * Invoice hash: SHA-256 of the canonicalized invoice without UBLExtensions,
     * cac:Signature and the QR AdditionalDocumentReference.
     */
    public function hash(string $xml): string
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;

        if (! $dom->loadXML($xml)) {
            throw new ZatcaException('Invalid invoice XML.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $queries = [
            '//ext:UBLExtensions',
            '//cac:Signature',
            "//cac:AdditionalDocumentReference[cbc:ID='QR']",
        ];

        foreach ($queries as $query) {
            foreach (iterator_to_array($xpath->query($query)) as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        return base64_encode(hash('sha256', $dom->C14N(false, false), true));
    }

    private function signHash(string $hash, string $privateKey): string
    {
        if (! openssl_sign(base64_decode($hash), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new ZatcaException('Unable to sign the invoice: '.openssl_error_string());
        }

        return base64_encode($signature);
    }

    /**
     * Signed properties block. ZATCA hashes it with the ds namespace declared on every ds element
     * and the exact indentation it has inside the document, so both forms are rendered here.
     */
    private function signedProperties(string $signingTime, Certificate $certificate, bool $forHashing): string
    {
        $xades = $forHashing ? ' xmlns:xades="http://uri.etsi.org/01903/v1.3.2#"' : '';
        $ds = $forHashing ? ' xmlns:ds="http://www.w3.org/2000/09/xmldsig#"' : '';
        $issuer = htmlspecialchars($certificate->issuerName(), ENT_XML1);

        $lines = [
            [0, '<xades:SignedProperties'.$xades.' Id="xadesSignedProperties">'],
            [1, '<xades:SignedSignatureProperties>'],
            [2, '<xades:SigningTime>'.$signingTime.'</xades:SigningTime>'],
            [2, '<xades:SigningCertificate>'],
            [3, '<xades:Cert>'],
            [4, '<xades:CertDigest>'],
            [5, '<ds:DigestMethod'.$ds.' Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'],
            [5, '<ds:DigestValue'.$ds.'>'.$certificate->hash().'</ds:DigestValue>'],
            [4, '</xades:CertDigest>'],
            [4, '<xades:IssuerSerial>'],
            [5, '<ds:X509IssuerName'.$ds.'>'.$issuer.'</ds:X509IssuerName>'],
            [5, '<ds:X509SerialNumber'.$ds.'>'.$certificate->serialNumber().'</ds:X509SerialNumber>'],
            [4, '</xades:IssuerSerial>'],
            [3, '</xades:Cert>'],
            [2, '</xades:SigningCertificate>'],
            [1, '</xades:SignedSignatureProperties>'],
            [0, '</xades:SignedProperties>'],
        ];

        // The block starts at column 36 in the document; the first line is already indented there
        return collect($lines)
            ->map(fn ($line, $i) => ($i === 0 ? '' : str_repeat(' ', 36 + $line[0] * 4)).$line[1])
            ->implode("\n");
    }

    private function ublExtensions(): string
    {
        return <<<'XML'
            <ext:UBLExtensions>
                <ext:UBLExtension>
                    <ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI>
                    <ext:ExtensionContent>
                        <sig:UBLDocumentSignatures xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2" xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2" xmlns:sbc="urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2">
                            <sac:SignatureInformation>
                                <cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>
                                <sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>
                                <ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="signature">
                                    <ds:SignedInfo>
                                        <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
                                        <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>
                                        <ds:Reference Id="invoiceSignedData" URI="">
                                            <ds:Transforms>
                                                <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                                    <ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath>
                                                </ds:Transform>
                                                <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                                    <ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath>
                                                </ds:Transform>
                                                <ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116">
                                                    <ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])</ds:XPath>
                                                </ds:Transform>
                                                <ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>
                                            </ds:Transforms>
                                            <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                            <ds:DigestValue>{{INVOICE_HASH}}</ds:DigestValue>
                                        </ds:Reference>
                                        <ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">
                                            <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                                            <ds:DigestValue>{{SIGNED_PROPERTIES_HASH}}</ds:DigestValue>
                                        </ds:Reference>
                                    </ds:SignedInfo>
                                    <ds:SignatureValue>{{SIGNATURE}}</ds:SignatureValue>
                                    <ds:KeyInfo>
                                        <ds:X509Data>
                                            <ds:X509Certificate>{{CERTIFICATE}}</ds:X509Certificate>
                                        </ds:X509Data>
                                    </ds:KeyInfo>
                                    <ds:Object>
                                        <xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="signature">
                                            {{SIGNED_PROPERTIES}}
                                        </xades:QualifyingProperties>
                                    </ds:Object>
                                </ds:Signature>
                            </sac:SignatureInformation>
                        </sig:UBLDocumentSignatures>
                    </ext:ExtensionContent>
                </ext:UBLExtension>
            </ext:UBLExtensions>
        XML;
    }
}
