<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class DocumentIdentity
{
    public function __construct(
        private FilingEngine $filingEngine,
        private DocumentUserLocks $userLocks,
        private DocumentCapabilities $capabilities,
    ) {}

    public function isCustomName(Document $document): bool
    {
        $title = $this->stringOrNull($document->title);

        if ($title === null) {
            return false;
        }

        $type = $this->stringOrNull($document->document_type);
        if ($this->filingEngine->isGenericTitle($title, $type)) {
            return false;
        }

        $extractedTitle = $this->extractedString($document, 'title');
        if ($extractedTitle !== null && strcasecmp($title, $extractedTitle) === 0) {
            return false;
        }

        $filenameStem = $this->filenameStem($document->original_filename);
        if ($filenameStem !== null && strcasecmp($title, $filenameStem) === 0) {
            return false;
        }

        return true;
    }

    public function primary(Document $document): string
    {
        if ($this->isCustomName($document)) {
            return (string) $document->title;
        }

        $computed = $this->computedPrimary($document);
        if ($computed !== null) {
            return $computed;
        }

        $filenameStem = $this->filenameStem($document->original_filename);
        if ($filenameStem !== null && ! $this->filingEngine->isGenericTitle($filenameStem, $document->document_type)) {
            return $filenameStem;
        }

        return 'Document #'.$document->id;
    }

    public function secondary(Document $document): ?string
    {
        return $this->computedSecondary($document);
    }

    public function displayAmount(Document $document, bool $compact = false): ?string
    {
        return $this->formatLegacyAmount($document, $compact);
    }

    public function formatMoneyValue(mixed $amount, ?string $currency = null, bool $compact = false): ?string
    {
        if ($amount === null || ! is_numeric($amount) || (float) $amount == 0.0) {
            return null;
        }

        return $this->formatMoney($amount, $this->stringOrNull($currency) ?? 'MYR', $compact);
    }

    public function displayAmountLabel(Document $document): ?string
    {
        if ($this->displayAmount($document) === null) {
            return null;
        }

        $locks = $this->userLocks->read($document);
        $data = is_array($document->extracted_data) ? $document->extracted_data : [];
        $role = is_string($data['interpretation']['primary_amount_role'] ?? null)
            ? $data['interpretation']['primary_amount_role']
            : null;

        if (in_array('amount', $locks['locked_fields'], true) || $role === null) {
            return $this->labelForDocumentType($document->document_type);
        }

        return $this->labelForRole($role);
    }

    /**
     * Compact type-relevant facts for detail view (label => value).
     *
     * @return list<array{label: string, value: string}>
     */
    public function facts(Document $document): array
    {
        $type = $this->stringOrNull($document->document_type);
        $data = is_array($document->extracted_data) ? $document->extracted_data : [];
        $amounts = is_array($data['amounts'] ?? null) ? $data['amounts'] : [];
        $dates = is_array($data['dates'] ?? null) ? $data['dates'] : [];
        $locks = $this->userLocks->read($document);
        $amountLocked = in_array('amount', $locks['locked_fields'], true);
        $dateLocked = in_array('document_date', $locks['locked_fields'], true);

        $facts = [];
        $push = function (string $label, ?string $value) use (&$facts): void {
            if ($value !== null && trim($value) !== '') {
                $facts[] = ['label' => $label, 'value' => $value];
            }
        };

        $merchant = $this->stringOrNull($document->merchant);
        $issuer = $this->extractedString($document, 'issuer') ?? $merchant;
        $reference = $this->stringOrNull($document->reference_number);
        $authoritativeAmount = $this->formatLegacyAmount($document);
        $authoritativeDate = $this->formatDate($document);

        if ($type === 'Insurance Policy') {
            $push('Provider', $issuer);
            $push('Insured', $this->extractedString($document, 'insured') ?? $this->extractedString($document, 'subject'));
            $push(
                'Premium',
                $amountLocked ? $authoritativeAmount : $this->formatAmountByRole($amounts, 'premium'),
            );
            $push('Coverage', $this->formatAmountByRole($amounts, 'coverage'));
            $push('Expiry', $this->formatDateByRole($dates, 'expiry') ?? $this->formatDateByRole($dates, 'renewal'));
        } elseif ($type === 'Invoice') {
            $push('Issuer', $issuer);
            $push('Reference', $reference);
            $push(
                'Amount due',
                $amountLocked
                    ? $authoritativeAmount
                    : ($this->formatAmountByRole($amounts, 'total_due') ?? $authoritativeAmount),
            );
            $push('Due date', $this->formatDateByRole($dates, 'due'));
            if (! $amountLocked) {
                $push('Payment status', $this->paymentStatusLabel($amounts, 'total_due'));
            }
        } elseif ($type === 'Receipt') {
            $push('Merchant', $merchant);
            $push(
                'Transaction date',
                $dateLocked
                    ? $authoritativeDate
                    : ($this->formatDateByRole($dates, 'transaction') ?? $authoritativeDate),
            );
            $push(
                'Total paid',
                $amountLocked
                    ? $authoritativeAmount
                    : ($this->formatAmountByRole($amounts, 'paid') ?? $authoritativeAmount),
            );
        } elseif ($type === 'Academic Certificate') {
            $push('Institution', $this->extractedString($document, 'institution') ?? $merchant);
            $push('Qualification', $this->extractedString($document, 'subject'));
            $push('Date', $authoritativeDate);
        } elseif ($type === 'Warranty') {
            $push('Product', $this->extractedString($document, 'product_name') ?? $this->extractedString($document, 'subject'));
            $push('Brand', $this->extractedString($document, 'brand'));
            $push('Expiry', $this->formatDateByRole($dates, 'expiry') ?? $this->formatDateByRole($dates, 'renewal'));
        } elseif ($type === 'Bank Statement') {
            $push('Institution', $merchant);
            $push('Period', $this->extractedString($document, 'statement_period')
                ?? $this->formatDateByRole($dates, 'period_start'));
            $push('Balance', $this->formatAmountByRole($amounts, 'balance'));
        } else {
            $push('Document type', $type);
            $push('Document date', $authoritativeDate);
            $push('Organisation', $merchant);
            $push('Reference', $reference);
            $push('Amount', $authoritativeAmount);
        }

        if (! collect($facts)->contains(fn (array $fact) => $fact['label'] === 'Expiry')) {
            $caps = $this->capabilities->for($document);
            if ($caps['has_expiry']) {
                $push(
                    'Expiry',
                    $this->formatDateByRole($dates, 'expiry') ?? $this->formatDateByRole($dates, 'renewal'),
                );
            }
        }

        return $facts;
    }

    private function computedPrimary(Document $document): ?string
    {
        $type = $this->stringOrNull($document->document_type);
        $merchant = $this->stringOrNull($document->merchant);
        $issuer = $this->extractedString($document, 'issuer') ?? $merchant;
        $subject = $this->extractedString($document, 'subject');
        $insured = $this->extractedString($document, 'insured');
        $product = $this->extractedString($document, 'product_name');
        $parties = $this->extractedString($document, 'parties');
        $institution = $this->extractedString($document, 'institution');

        return match ($type) {
            'Invoice' => $issuer ?? $subject,
            'Receipt', 'Payslip' => $merchant ?? $issuer,
            'Insurance Policy' => $insured ?? $subject ?? $product ?? $merchant,
            'Bank Statement' => $merchant ?? $issuer,
            'Contract' => $subject ?? $parties ?? $merchant,
            'Warranty' => $product ?? $subject ?? $merchant,
            'Academic Certificate' => $subject ?? $institution ?? $merchant,
            default => $subject ?? $merchant ?? $issuer ?? $institution ?? $product,
        };
    }

    private function computedSecondary(Document $document): ?string
    {
        $type = $this->stringOrNull($document->document_type);
        $reference = $this->stringOrNull($document->reference_number);
        $date = $this->formatDate($document);
        $data = is_array($document->extracted_data) ? $document->extracted_data : [];
        $amounts = is_array($data['amounts'] ?? null) ? $data['amounts'] : [];
        $dates = is_array($data['dates'] ?? null) ? $data['dates'] : [];
        $locks = $this->userLocks->read($document);
        $amountLocked = in_array('amount', $locks['locked_fields'], true);

        $paid = $amountLocked ? null : $this->formatAmountByRole($amounts, 'paid');
        $due = $amountLocked ? null : $this->formatAmountByRole($amounts, 'total_due');
        $premium = $amountLocked ? null : $this->formatAmountByRole($amounts, 'premium');
        $legacy = $this->formatLegacyAmount($document);
        $expiry = $this->formatDateByRole($dates, 'expiry') ?? $this->formatDateByRole($dates, 'renewal');
        $dueDate = $this->formatDateByRole($dates, 'due');
        $amount = $amountLocked ? $legacy : ($paid ?? $due ?? $premium ?? $legacy);
        $merchant = $this->stringOrNull($document->merchant);
        $issuer = $this->extractedString($document, 'issuer') ?? $merchant;
        $policy = $this->extractedString($document, 'policy_number') ?? $reference;
        $period = $this->extractedString($document, 'statement_period');
        $brand = $this->extractedString($document, 'brand');
        $serial = $this->extractedString($document, 'serial_number');
        $institution = $this->extractedString($document, 'institution');
        $parties = $this->extractedString($document, 'parties');

        $expiryLabel = $expiry !== null ? 'Expires '.$expiry : null;

        $parts = match ($type) {
            'Invoice' => array_filter([$reference, $amountLocked ? $legacy : $due, $dueDate]),
            'Receipt', 'Payslip' => array_filter([$date, $amountLocked ? $legacy : ($paid ?? $amount)]),
            'Insurance Policy' => array_filter([
                $issuer,
                $policy !== null ? 'Policy #'.$policy : null,
                $expiryLabel,
            ]),
            'Bank Statement' => array_filter([$period ?? $date]),
            'Contract' => array_filter([$date, $reference, $parties !== $this->computedPrimary($document) ? $parties : null]),
            'Warranty' => array_filter([$brand, $expiry ?? $date, $serial !== null ? 'S/N '.$serial : null]),
            'Academic Certificate' => array_filter([$institution, $date]),
            default => array_filter([$date, $reference, $amount]),
        };

        $parts = array_values(array_unique(array_filter($parts, fn ($part) => is_string($part) && trim($part) !== '')));

        if ($expiryLabel !== null && ! collect($parts)->contains(fn (string $part) => str_starts_with($part, 'Expires '))) {
            if ($type !== 'Warranty') {
                $parts[] = $expiryLabel;
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode(' · ', $parts);
    }

    private function labelForRole(string $role): string
    {
        return match ($role) {
            'paid' => 'Total Paid',
            'total_due' => 'Invoice Total',
            'premium' => 'Premium',
            'balance' => 'Balance',
            'coverage' => 'Coverage',
            'refund' => 'Refund',
            default => 'Amount',
        };
    }

    private function labelForDocumentType(?string $documentType): string
    {
        return match ($documentType) {
            'Receipt', 'Payslip' => 'Total Paid',
            'Invoice' => 'Invoice Total',
            'Insurance Policy' => 'Premium',
            default => 'Amount',
        };
    }

    private function extractedString(Document $document, string $key): ?string
    {
        $data = is_array($document->extracted_data) ? $document->extracted_data : [];

        return $this->stringOrNull($data[$key] ?? null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function filenameStem(?string $filename): ?string
    {
        if ($filename === null || trim($filename) === '') {
            return null;
        }

        $stem = pathinfo($filename, PATHINFO_FILENAME);

        return $this->stringOrNull(is_string($stem) ? $stem : null);
    }

    private function formatDate(Document $document): ?string
    {
        if ($document->document_date) {
            return $document->document_date->format('j M Y');
        }

        $raw = $this->extractedString($document, 'document_date');
        if ($raw === null) {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('j M Y');
        } catch (\Throwable) {
            return $raw;
        }
    }

    private function formatLegacyAmount(Document $document, bool $compact = false): ?string
    {
        if ($document->amount === null) {
            return null;
        }

        $currency = $this->stringOrNull($document->currency) ?? 'MYR';

        return $this->formatMoney($document->amount, $currency, $compact);
    }

    /**
     * @param  list<array<string, mixed>>  $amounts
     */
    private function formatAmountByRole(array $amounts, string $role): ?string
    {
        foreach ($amounts as $amount) {
            if (($amount['role'] ?? null) !== $role || empty($amount['value'])) {
                continue;
            }
            if (! empty($amount['uncertain'])) {
                continue;
            }
            $currency = is_string($amount['currency'] ?? null) ? $amount['currency'] : 'MYR';

            return $this->formatMoney($amount['value'], $currency);
        }

        return null;
    }

    /**
     * Comma-separated money; month cards compact at ≥100K (100K / 1M).
     */
    private function formatMoney(mixed $amount, string $currency, bool $compact = false): string
    {
        if (! is_numeric($amount)) {
            return $currency.' '.(string) $amount;
        }

        $value = (float) $amount;

        if ($compact && abs($value) >= 100_000) {
            $abbreviated = Number::abbreviate($value, maxPrecision: 1);

            return $currency.' '.($abbreviated === false ? number_format($value, 2, '.', ',') : $abbreviated);
        }

        $formatted = Number::format($value, precision: 2);

        return $currency.' '.($formatted === false ? number_format($value, 2, '.', ',') : $formatted);
    }

    /**
     * @param  list<array<string, mixed>>  $dates
     */
    private function formatDateByRole(array $dates, string $role): ?string
    {
        foreach ($dates as $date) {
            if (($date['role'] ?? null) !== $role || empty($date['value'])) {
                continue;
            }
            if (! empty($date['uncertain'])) {
                continue;
            }
            try {
                return Carbon::parse((string) $date['value'])->format('j M Y');
            } catch (\Throwable) {
                return (string) $date['value'];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $amounts
     */
    private function paymentStatusLabel(array $amounts, string $role): ?string
    {
        foreach ($amounts as $amount) {
            if (($amount['role'] ?? null) !== $role) {
                continue;
            }
            if (! empty($amount['uncertain'])) {
                continue;
            }
            $status = is_string($amount['payment_status'] ?? null) ? $amount['payment_status'] : null;
            if ($status === null || $status === 'unknown') {
                return 'Unknown';
            }

            return Str::of($status)->replace('_', ' ')->title()->toString();
        }

        return null;
    }
}
