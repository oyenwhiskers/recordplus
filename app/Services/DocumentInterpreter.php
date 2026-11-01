<?php

namespace App\Services;

class DocumentInterpreter
{
    /** @var list<string> */
    public const AMOUNT_ROLES = [
        'paid',
        'total_due',
        'premium',
        'coverage',
        'balance',
        'refund',
        'other',
    ];

    /** @var list<string> */
    public const PAYMENT_STATUSES = [
        'unknown',
        'unpaid',
        'paid',
        'partial',
        'evidence_present',
    ];

    /** @var list<string> */
    public const DATE_ROLES = [
        'document',
        'transaction',
        'due',
        'expiry',
        'renewal',
        'period_start',
        'period_end',
    ];

    /**
     * @param  array<string, mixed>  $extractedData  Sanitized extraction including amounts/dates
     * @return array{
     *     document_date: ?string,
     *     amount: ?string,
     *     currency: ?string,
     *     extracted_data: array<string, mixed>
     * }
     */
    public function interpret(?string $documentType, array $extractedData): array
    {
        $amounts = is_array($extractedData['amounts'] ?? null) ? $extractedData['amounts'] : [];
        $dates = is_array($extractedData['dates'] ?? null) ? $extractedData['dates'] : [];

        $hasStructured = $amounts !== [] || $dates !== [];

        $primaryDate = $this->primaryDocumentDate($dates);
        $primaryAmount = $this->primaryAmount($documentType, $amounts);
        $expenseEligible = $this->isExpenseEligible($amounts);

        $extractedData['interpretation'] = [
            'has_structured_money_dates' => $hasStructured,
            'expense_eligible' => $expenseEligible,
            'primary_amount_role' => $primaryAmount['role'] ?? null,
            'primary_date_role' => $primaryDate['role'] ?? null,
        ];

        return [
            'document_date' => $primaryDate['value'] ?? null,
            'amount' => $primaryAmount['value'] ?? null,
            'currency' => $primaryAmount['currency'] ?? null,
            'extracted_data' => $extractedData,
        ];
    }

    /**
     * Legacy rows without amounts/dates: keep columns; mark not expense-eligible.
     *
     * @param  array<string, mixed>  $extractedData
     * @return array<string, mixed>
     */
    public function markLegacyInterpretation(array $extractedData): array
    {
        $hasStructured = (is_array($extractedData['amounts'] ?? null) && $extractedData['amounts'] !== [])
            || (is_array($extractedData['dates'] ?? null) && $extractedData['dates'] !== []);

        if ($hasStructured) {
            return $extractedData;
        }

        $extractedData['interpretation'] = [
            'has_structured_money_dates' => false,
            'expense_eligible' => false,
            'primary_amount_role' => null,
            'primary_date_role' => null,
        ];

        return $extractedData;
    }

    /**
     * @param  list<array<string, mixed>>  $dates
     * @return array{value: ?string, role: ?string}
     */
    private function primaryDocumentDate(array $dates): array
    {
        foreach (['document', 'transaction'] as $role) {
            foreach ($dates as $date) {
                if (($date['role'] ?? null) === $role && ! empty($date['value']) && empty($date['uncertain'])) {
                    return ['value' => (string) $date['value'], 'role' => $role];
                }
            }
        }

        return ['value' => null, 'role' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $amounts
     * @return array{value: ?string, currency: ?string, role: ?string}
     */
    private function primaryAmount(?string $documentType, array $amounts): array
    {
        $preferredRole = match ($documentType) {
            'Receipt' => 'paid',
            'Invoice' => 'total_due',
            'Insurance Policy' => 'premium',
            'Payslip' => 'paid',
            'Bank Statement' => null,
            default => null,
        };

        if ($preferredRole === null) {
            return ['value' => null, 'currency' => null, 'role' => null];
        }

        $matches = array_values(array_filter(
            $amounts,
            fn (array $amount): bool => ($amount['role'] ?? null) === $preferredRole
                && isset($amount['value'])
                && empty($amount['uncertain']),
        ));

        if (count($matches) !== 1) {
            return ['value' => null, 'currency' => null, 'role' => null];
        }

        $match = $matches[0];

        return [
            'value' => is_numeric($match['value']) ? (string) $match['value'] : null,
            'currency' => is_string($match['currency'] ?? null) ? $match['currency'] : null,
            'role' => $preferredRole,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $amounts
     */
    private function isExpenseEligible(array $amounts): bool
    {
        foreach ($amounts as $amount) {
            if (($amount['role'] ?? null) !== 'paid') {
                continue;
            }

            if (! empty($amount['uncertain'])) {
                continue;
            }

            $status = $amount['payment_status'] ?? 'unknown';
            if (in_array($status, ['paid', 'evidence_present'], true)) {
                return true;
            }
        }

        return false;
    }
}
