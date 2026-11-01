<?php

namespace App\Support;

final class CurrencyOptions
{
    /** @var list<string> */
    public const CODES = [
        'MYR',
        'SGD',
        'USD',
        'EUR',
        'GBP',
        'JPY',
        'AUD',
        'CNY',
        'THB',
        'IDR',
    ];

    public static function isAllowed(string $code): bool
    {
        return in_array(strtoupper(trim($code)), self::CODES, true);
    }

    public static function normalize(?string $code, string $fallback = 'MYR'): string
    {
        $cleaned = strtoupper(trim((string) $code));

        return $cleaned !== '' ? $cleaned : $fallback;
    }
}
