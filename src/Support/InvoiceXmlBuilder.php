<?php

namespace delwarhossaindev\Zatca\Support;

use delwarhossaindev\Zatca\Data\Address;
use delwarhossaindev\Zatca\Data\Buyer;
use delwarhossaindev\Zatca\Data\InvoiceData;
use delwarhossaindev\Zatca\Data\InvoiceLine;
use delwarhossaindev\Zatca\Models\ZatcaDevice;

/**
 * Builds the UBL 2.1 invoice XML in the format ZATCA expects (4 space indentation).
 *
 * The UBL extensions (signature) and the QR reference are written with placeholders
 * that the InvoiceSigner fills in. They are excluded from the invoice hash together
 * with cac:Signature, so the hash is identical before and after signing.
 */
class InvoiceXmlBuilder
{
    public const PLACEHOLDER_UBL_EXTENSIONS = '{{UBL_EXTENSIONS}}';
    public const PLACEHOLDER_QR = '{{QR_CODE}}';

    private const INDENT = '    ';

    public function build(InvoiceData $invoice, ZatcaDevice $seller, int $icv, string $previousHash): string
    {
        $children = array_filter([
            ['cbc:ProfileID', 'reporting:1.0'],
            ['cbc:ID', $invoice->number],
            ['cbc:UUID', $invoice->uuid],
            ['cbc:IssueDate', $invoice->issueDate()],
            ['cbc:IssueTime', $invoice->issueTime()],
            ['cbc:InvoiceTypeCode', $invoice->documentCode(), ['name' => $invoice->transactionCode()]],
            ['cbc:DocumentCurrencyCode', $invoice->currency],
            ['cbc:TaxCurrencyCode', 'SAR'],
            $invoice->isNote() ? ['cac:BillingReference', [
                ['cac:InvoiceDocumentReference', [
                    ['cbc:ID', $invoice->originalInvoiceNumber],
                ]],
            ]] : null,
            ['cac:AdditionalDocumentReference', [
                ['cbc:ID', 'ICV'],
                ['cbc:UUID', (string) $icv],
            ]],
            ['cac:AdditionalDocumentReference', [
                ['cbc:ID', 'PIH'],
                ['cac:Attachment', [
                    ['cbc:EmbeddedDocumentBinaryObject', $previousHash, ['mimeCode' => 'text/plain']],
                ]],
            ]],
            ['cac:AdditionalDocumentReference', [
                ['cbc:ID', 'QR'],
                ['cac:Attachment', [
                    ['cbc:EmbeddedDocumentBinaryObject', self::PLACEHOLDER_QR, ['mimeCode' => 'text/plain']],
                ]],
            ]],
            ['cac:Signature', [
                ['cbc:ID', 'urn:oasis:names:specification:ubl:signature:Invoice'],
                ['cbc:SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades'],
            ]],
            $this->supplier($seller),
            $this->customer($invoice),
            $invoice->isSimplified() ? null : ['cac:Delivery', [
                ['cbc:ActualDeliveryDate', $invoice->issueDate()],
            ]],
            ['cac:PaymentMeans', array_filter([
                ['cbc:PaymentMeansCode', $invoice->paymentMeans],
                $invoice->isNote() ? ['cbc:InstructionNote', $invoice->noteReason] : null,
            ])],
            ...$this->taxTotals($invoice),
            $this->monetaryTotal($invoice),
            ...$this->lines($invoice),
        ]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"'
            .' xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">'."\n";
        $xml .= self::PLACEHOLDER_UBL_EXTENSIONS."\n";

        foreach ($children as $child) {
            $xml .= $this->render($child, 1);
        }

        return $xml.'</Invoice>';
    }

    private function supplier(ZatcaDevice $seller): array
    {
        return ['cac:AccountingSupplierParty', [
            ['cac:Party', array_filter([
                filled($seller->crn) ? ['cac:PartyIdentification', [
                    ['cbc:ID', $seller->crn, ['schemeID' => 'CRN']],
                ]] : null,
                $this->address(new Address(
                    street: $seller->street,
                    buildingNumber: $seller->building_number,
                    district: $seller->district,
                    city: $seller->city,
                    postalCode: $seller->postal_code,
                    plotIdentification: $seller->plot_identification,
                )),
                ['cac:PartyTaxScheme', [
                    ['cbc:CompanyID', $seller->vat_number],
                    ['cac:TaxScheme', [['cbc:ID', 'VAT']]],
                ]],
                ['cac:PartyLegalEntity', [
                    ['cbc:RegistrationName', $seller->seller_name],
                ]],
            ])],
        ]];
    }

    private function customer(InvoiceData $invoice): array
    {
        $buyer = $invoice->buyer ?? new Buyer('Cash Customer');

        return ['cac:AccountingCustomerParty', [
            ['cac:Party', array_filter([
                filled($buyer->identification) ? ['cac:PartyIdentification', [
                    ['cbc:ID', $buyer->identification, ['schemeID' => $buyer->identificationScheme ?? 'OTH']],
                ]] : null,
                $buyer->address ? $this->address($buyer->address) : null,
                filled($buyer->vatNumber) ? ['cac:PartyTaxScheme', [
                    ['cbc:CompanyID', $buyer->vatNumber],
                    ['cac:TaxScheme', [['cbc:ID', 'VAT']]],
                ]] : null,
                ['cac:PartyLegalEntity', [
                    ['cbc:RegistrationName', $buyer->name],
                ]],
            ])],
        ]];
    }

    private function address(Address $address): array
    {
        return ['cac:PostalAddress', array_filter([
            ['cbc:StreetName', $address->street],
            ['cbc:BuildingNumber', $address->buildingNumber],
            filled($address->plotIdentification) ? ['cbc:PlotIdentification', $address->plotIdentification] : null,
            ['cbc:CitySubdivisionName', $address->district],
            ['cbc:CityName', $address->city],
            ['cbc:PostalZone', $address->postalCode],
            ['cac:Country', [['cbc:IdentificationCode', $address->countryCode]]],
        ])];
    }

    private function taxTotals(InvoiceData $invoice): array
    {
        $currency = ['currencyID' => $invoice->currency];
        $subtotals = [];

        foreach ($invoice->taxSubtotals() as $subtotal) {
            $subtotals[] = ['cac:TaxSubtotal', [
                ['cbc:TaxableAmount', $this->amount($subtotal['taxable']), $currency],
                ['cbc:TaxAmount', $this->amount($subtotal['tax']), $currency],
                ['cac:TaxCategory', array_filter([
                    ['cbc:ID', $subtotal['category'], ['schemeID' => 'UN/ECE 5305', 'schemeAgencyID' => '6']],
                    ['cbc:Percent', $this->amount($subtotal['percent'])],
                    $subtotal['reason_code'] ? ['cbc:TaxExemptionReasonCode', $subtotal['reason_code']] : null,
                    $subtotal['reason'] ? ['cbc:TaxExemptionReason', $subtotal['reason']] : null,
                    ['cac:TaxScheme', [
                        ['cbc:ID', 'VAT', ['schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']],
                    ]],
                ])],
            ]];
        }

        // The first TaxTotal is in the tax currency (SAR), the second carries the breakdown
        return [
            ['cac:TaxTotal', [
                ['cbc:TaxAmount', $this->amount($invoice->vatAmount()), ['currencyID' => 'SAR']],
            ]],
            ['cac:TaxTotal', [
                ['cbc:TaxAmount', $this->amount($invoice->vatAmount()), $currency],
                ...$subtotals,
            ]],
        ];
    }

    private function monetaryTotal(InvoiceData $invoice): array
    {
        $currency = ['currencyID' => $invoice->currency];

        return ['cac:LegalMonetaryTotal', [
            ['cbc:LineExtensionAmount', $this->amount($invoice->lineExtensionAmount()), $currency],
            ['cbc:TaxExclusiveAmount', $this->amount($invoice->lineExtensionAmount()), $currency],
            ['cbc:TaxInclusiveAmount', $this->amount($invoice->totalAmount()), $currency],
            ['cbc:AllowanceTotalAmount', '0.00', $currency],
            ['cbc:PrepaidAmount', '0.00', $currency],
            ['cbc:PayableAmount', $this->amount($invoice->totalAmount()), $currency],
        ]];
    }

    private function lines(InvoiceData $invoice): array
    {
        $currency = ['currencyID' => $invoice->currency];
        $lines = [];

        foreach (array_values($invoice->lines) as $index => $line) {
            /** @var InvoiceLine $line */
            $lines[] = ['cac:InvoiceLine', [
                ['cbc:ID', (string) ($index + 1)],
                ['cbc:InvoicedQuantity', number_format($line->quantity, 6, '.', ''), ['unitCode' => $line->unitCode]],
                ['cbc:LineExtensionAmount', $this->amount($line->lineExtensionAmount()), $currency],
                ['cac:TaxTotal', [
                    ['cbc:TaxAmount', $this->amount($line->vatAmount()), $currency],
                    ['cbc:RoundingAmount', $this->amount($line->lineExtensionAmount() + $line->vatAmount()), $currency],
                ]],
                ['cac:Item', [
                    ['cbc:Name', $line->name],
                    ['cac:ClassifiedTaxCategory', [
                        ['cbc:ID', $line->vatCategory],
                        ['cbc:Percent', $this->amount($line->vatPercent)],
                        ['cac:TaxScheme', [['cbc:ID', 'VAT']]],
                    ]],
                ]],
                ['cac:Price', [
                    ['cbc:PriceAmount', $this->amount($line->unitPrice), $currency],
                ]],
            ]];
        }

        return $lines;
    }

    /**
     * Render a [name, value|children, attributes] node.
     */
    private function render(array $node, int $level): string
    {
        [$name, $content] = $node;
        $attributes = '';

        foreach ($node[2] ?? [] as $key => $value) {
            $attributes .= ' '.$key.'="'.$this->escape($value).'"';
        }

        $indent = str_repeat(self::INDENT, $level);

        if (! is_array($content)) {
            return "$indent<$name$attributes>".$this->escape((string) $content)."</$name>\n";
        }

        $xml = "$indent<$name$attributes>\n";

        foreach ($content as $child) {
            $xml .= $this->render($child, $level + 1);
        }

        return $xml."$indent</$name>\n";
    }

    private function amount(float|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
