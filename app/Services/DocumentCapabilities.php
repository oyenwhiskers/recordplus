<?php

namespace App\Services;

use App\Models\Document;

class DocumentCapabilities
{
    /**
     * @return array{
     *     has_expiry: bool,
     *     has_money: bool,
     *     expense_eligible: bool,
     *     has_coverage: bool,
     *     has_balance: bool,
     *     expiry_date: ?string,
     *     expiry_role: ?string
     * }
     */
    public function for(Document $document): array
    {
        $data = is_array($document->extracted_data) ? $document->extracted_data : [];
        $amounts = is_array($data['amounts'] ?? null) ? $data['amounts'] : [];
        $dates = is_array($data['dates'] ?? null) ? $data['dates'] : [];
        $interpretation = is_array($data['interpretation'] ?? null) ? $data['interpretation'] : [];

        $expiry = $this->firstConfidentDate($dates, ['expiry', 'renewal']);

        return [
            'has_expiry' => $expiry !== null,
            'has_money' => $document->amount !== null,
            'expense_eligible' => (bool) ($interpretation['expense_eligible'] ?? false),
            'has_coverage' => $this->hasConfidentAmountRole($amounts, 'coverage'),
            'has_balance' => $this->hasConfidentAmountRole($amounts, 'balance'),
            'expiry_date' => $expiry['value'] ?? null,
            'expiry_role' => $expiry['role'] ?? null,
        ];
    }

    /**
     * @return list<array{role: string, value: string}>
     */
    public function actionableExpiryDates(Document $document): array
    {
        $data = is_array($document->extracted_data) ? $document->extracted_data : [];
        $dates = is_array($data['dates'] ?? null) ? $data['dates'] : [];
        $found = [];

        foreach (['expiry', 'renewal'] as $role) {
            $date = $this->firstConfidentDate($dates, [$role]);
            if ($date !== null) {
                $found[] = $date;
            }
        }

        return $found;
    }

    /**
     * @param  list<array<string, mixed>>  $dates
     * @param  list<string>  $roles
     * @return array{role: string, value: string}|null
     */
    private function firstConfidentDate(array $dates, array $roles): ?array
    {
        foreach ($roles as $role) {
            foreach ($dates as $date) {
                if (($date['role'] ?? null) !== $role) {
                    continue;
                }
                if (! empty($date['uncertain']) || empty($date['value'])) {
                    continue;
                }

                return [
                    'role' => $role,
                    'value' => (string) $date['value'],
                ];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $amounts
     */
    private function hasConfidentAmountRole(array $amounts, string $role): bool
    {
        foreach ($amounts as $amount) {
            if (($amount['role'] ?? null) !== $role) {
                continue;
            }
            if (! empty($amount['uncertain']) || empty($amount['value'])) {
                continue;
            }

            return true;
        }

        return false;
    }
}
