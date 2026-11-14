<?php

namespace delwarhossaindev\Zatca\Data;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Str;
use delwarhossaindev\Zatca\Exceptions\ZatcaException;

/**
 * Everything ZATCA needs to know about one invoice, credit note or debit note.
 * Map your own invoice model to this class.
 */
final class InvoiceData
{
    public const STANDARD = 'standard';
    public const SIMPLIFIED = 'simplified';

    public const INVOICE = 'invoice';
    public const CREDIT_NOTE = 'credit';
    public const DEBIT_NOTE = 'debit';

    public const DOCUMENT_CODES = [
        self::INVOICE => '388',
        self::DEBIT_NOTE => '383',
        self::CREDIT_NOTE => '381',
    ];

    public readonly string $uuid;

    public readonly DateTimeImmutable $issuedAt;

    /**
     * @param  string  $type  standard (B2B, cleared) or simplified (B2C, reported)
     * @param  InvoiceLine[]  $lines
     * @param  string  $documentType  invoice, credit or debit
     * @param  string|null  $originalInvoiceNumber  Required for credit and debit notes
     * @param  string|null  $noteReason  Required for credit and debit notes
     * @param  string  $paymentMeans  UN/ECE 4461 code: 10 cash, 30 credit, 42 bank account, 48 card
     */
    public function __construct(
        public readonly string $number,
        public readonly string $type,
        public readonly array $lines,
        public readonly ?Buyer $buyer = null,
        public readonly string $documentType = self::INVOICE,
        public readonly ?string $originalInvoiceNumber = null,
        public readonly ?string $noteReason = null,
        ?DateTimeInterface $issuedAt = null,
        ?string $uuid = null,
        public readonly string $currency = 'SAR',
        public readonly string $paymentMeans = '10',
    ) {
        $this->uuid = $uuid ?? (string) Str::uuid();
        $this->issuedAt = DateTimeImmutable::createFromInterface($issuedAt ?? new DateTimeImmutable())
            ->setTimezone(new DateTimeZone(config('zatca.timezone', 'Asia/Riyadh')));

        $this->validate();
    }

    public function isSimplified(): bool
    {
        return $this->type === self::SIMPLIFIED;
    }

    public function isNote(): bool
    {
        return $this->documentType !== self::INVOICE;
    }

    public function documentCode(): string
    {
        return self::DOCUMENT_CODES[$this->documentType];
    }

    /**
     * KSA-2 invoice type transaction code (NNPNESB).
     */
    public function transactionCode(): string
    {
        return $this->isSimplified() ? '0200000' : '0100000';
    }

    public function issueDate(): string
    {
        return $this->issuedAt->format('Y-m-d');
    }

    public function issueTime(): string
    {
        return $this->issuedAt->format('H:i:s');
    }

    public function label(): string
    {
        $type = $this->isSimplified() ? 'Simplified' : 'Standard';

        return match ($this->documentType) {
            self::CREDIT_NOTE => "$type Credit Note",
            self::DEBIT_NOTE => "$type Debit Note",
            default => "$type Tax Invoice",
        };
    }

    public function lineExtensionAmount(): float
    {
        return round(array_sum(array_map(fn (InvoiceLine $line) => $line->lineExtensionAmount(), $this->lines)), 2);
    }

    /**
     * Document VAT is calculated per category on the taxable amount (BR-S-09).
     */
    public function vatAmount(): float
    {
        return round(array_sum(array_column($this->taxSubtotals(), 'tax')), 2);
    }

    public function totalAmount(): float
    {
        return round($this->lineExtensionAmount() + $this->vatAmount(), 2);
    }

    /**
     * Taxable and tax amounts grouped by VAT category, rate and exemption reason.
     *
     * @return array<int, array{category: string, percent: float, taxable: float, tax: float, reason_code: ?string, reason: ?string}>
     */
    public function taxSubtotals(): array
    {
        $groups = [];

        foreach ($this->lines as $line) {
            $key = $line->vatCategory.'|'.$line->vatPercent.'|'.$line->exemptionReasonCode;
            $groups[$key] ??= [
                'category' => $line->vatCategory,
                'percent' => $line->vatPercent,
                'taxable' => 0,
                'reason_code' => $line->exemptionReasonCode,
                'reason' => $line->exemptionReason,
            ];
            $groups[$key]['taxable'] += $line->lineExtensionAmount();
        }

        return array_values(array_map(function ($group) {
            $group['taxable'] = round($group['taxable'], 2);
            $group['tax'] = round($group['taxable'] * $group['percent'] / 100, 2);

            return $group;
        }, $groups));
    }

    private function validate(): void
    {
        if (! in_array($this->type, [self::STANDARD, self::SIMPLIFIED], true)) {
            throw new ZatcaException('Invoice type must be "standard" or "simplified".');
        }

        if (! isset(self::DOCUMENT_CODES[$this->documentType])) {
            throw new ZatcaException('Document type must be "invoice", "credit" or "debit".');
        }

        if ($this->lines === [] || array_filter($this->lines, fn ($line) => ! $line instanceof InvoiceLine)) {
            throw new ZatcaException('An invoice needs at least one InvoiceLine.');
        }

        if ($this->isNote() && (! $this->originalInvoiceNumber || ! $this->noteReason)) {
            throw new ZatcaException('Credit and debit notes need the original invoice number and a reason.');
        }

        if (! $this->isSimplified() && (! $this->buyer || ! $this->buyer->address)) {
            throw new ZatcaException('Standard invoices need the buyer name and address.');
        }
    }
}
