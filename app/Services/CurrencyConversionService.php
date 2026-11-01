<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Support\CurrencyOptions;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Live Frankfurter rates by document date (same endpoint as the mobile app).
 *
 * GET https://api.frankfurter.dev/v1/{Y-m-d}?base=FROM&symbols=TO
 */
class CurrencyConversionService
{
    private const BASE_URL = 'https://api.frankfurter.dev/v1';

    private const CACHE_TTL_HOURS = 24;

    private const PREFETCH_BATCH_SIZE = 5;

    /** @var array<string, float|null> */
    private array $memory = [];

    /**
     * Prefetch unique (date, from) pairs. Returns map key "Y-m-d|FROM" => rate into $to.
     *
     * @param  iterable<array{date: Carbon|string, from: string}>  $pairs
     * @return array<string, float>
     */
    public function prefetchRates(iterable $pairs, string $to): array
    {
        $toCode = CurrencyOptions::normalize($to);
        $unique = [];

        foreach ($pairs as $pair) {
            $fromCode = CurrencyOptions::normalize($pair['from']);
            if ($fromCode === $toCode) {
                continue;
            }

            $day = Carbon::parse($pair['date'])->startOfDay();
            $key = $this->rateKey($day, $fromCode);
            $unique[$key] = ['date' => $day, 'from' => $fromCode];
        }

        $out = [];
        $pending = [];

        foreach ($unique as $key => $item) {
            $cached = $this->rateFromCacheOrMemory($item['from'], $toCode, $item['date']);
            if ($cached !== null) {
                $out[$key] = $cached;

                continue;
            }
            $pending[] = $item;
        }

        foreach (array_chunk($pending, self::PREFETCH_BATCH_SIZE) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => collect($batch)->mapWithKeys(
                fn (array $item, int $index) => [
                    (string) $index => $pool
                        ->as((string) $index)
                        ->connectTimeout(3)
                        ->timeout(15)
                        ->get(self::BASE_URL.'/'.$item['date']->toDateString(), [
                            'base' => $item['from'],
                            'symbols' => $toCode,
                        ]),
                ],
            )->all());

            foreach ($batch as $index => $item) {
                $response = $responses[(string) $index] ?? null;
                $rate = $this->rateFromResponse($response, $toCode);
                $memKey = $this->rateKey($item['date'], $item['from']).'|'.$toCode;

                if ($rate === null) {
                    $this->memory[$memKey] = null;

                    continue;
                }

                $this->persistRate($item['from'], $toCode, $item['date'], $rate);
                $this->memory[$memKey] = $rate;
                $out[$this->rateKey($item['date'], $item['from'])] = $rate;
            }
        }

        return $out;
    }

    public function getRate(string $from, string $to, Carbon|string $date): ?float
    {
        $fromCode = CurrencyOptions::normalize($from);
        $toCode = CurrencyOptions::normalize($to);
        if ($fromCode === $toCode) {
            return 1.0;
        }

        $day = Carbon::parse($date)->startOfDay();
        $cached = $this->rateFromCacheOrMemory($fromCode, $toCode, $day);
        if ($cached !== null) {
            return $cached;
        }

        $fetched = $this->fetchRateFromApi($fromCode, $toCode, $day);
        $memKey = $this->rateKey($day, $fromCode).'|'.$toCode;

        if ($fetched === null) {
            $stale = ExchangeRate::query()
                ->whereDate('rate_date', $day->toDateString())
                ->where('from_currency', $fromCode)
                ->where('to_currency', $toCode)
                ->first();

            return $this->memory[$memKey] = $stale !== null ? (float) $stale->rate : null;
        }

        $this->persistRate($fromCode, $toCode, $day, $fetched);

        return $this->memory[$memKey] = $fetched;
    }

    /**
     * Sum document amounts into base currency. Skips undated / missing-rate foreign amounts.
     *
     * @param  Collection<int, object>|iterable<int, object>  $documents  Objects with amount, currency, document_date
     * @return array{total_in_base: float, convertible_count: int, skipped_count: int}
     */
    public function sumInBaseCurrency(iterable $documents, string $baseCurrency): array
    {
        $base = CurrencyOptions::normalize($baseCurrency);
        $rows = [];

        foreach ($documents as $document) {
            $amount = $document->amount ?? null;
            if ($amount === null || (float) $amount == 0.0) {
                continue;
            }
            $rows[] = $document;
        }

        if ($rows === []) {
            return [
                'total_in_base' => 0.0,
                'convertible_count' => 0,
                'skipped_count' => 0,
            ];
        }

        $pairs = [];
        foreach ($rows as $document) {
            $from = CurrencyOptions::normalize($document->currency ?? null);
            if ($from === $base) {
                continue;
            }
            $date = $document->document_date ?? null;
            if ($date === null) {
                continue;
            }
            $pairs[] = ['date' => $date, 'from' => $from];
        }

        $rates = $this->prefetchRates($pairs, $base);

        $total = 0.0;
        $convertible = 0;
        $skipped = 0;

        foreach ($rows as $document) {
            $amount = (float) $document->amount;
            $from = CurrencyOptions::normalize($document->currency ?? null);

            if ($from === $base) {
                $total += $amount;
                $convertible++;

                continue;
            }

            $date = $document->document_date ?? null;
            if ($date === null) {
                $skipped++;

                continue;
            }

            $key = $this->rateKey(Carbon::parse($date)->startOfDay(), $from);
            if (! isset($rates[$key])) {
                $skipped++;

                continue;
            }

            $total += $amount * $rates[$key];
            $convertible++;
        }

        return [
            'total_in_base' => $total,
            'convertible_count' => $convertible,
            'skipped_count' => $skipped,
        ];
    }

    private function rateFromCacheOrMemory(string $from, string $to, Carbon $day): ?float
    {
        $memKey = $this->rateKey($day, $from).'|'.$to;
        if (array_key_exists($memKey, $this->memory)) {
            return $this->memory[$memKey];
        }

        $cached = ExchangeRate::query()
            ->whereDate('rate_date', $day->toDateString())
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->first();

        if ($cached !== null && ! $this->isCacheStale($cached->fetched_at, $day)) {
            return $this->memory[$memKey] = (float) $cached->rate;
        }

        return null;
    }

    private function persistRate(string $from, string $to, Carbon $day, float $rate): void
    {
        ExchangeRate::query()->updateOrCreate(
            [
                'rate_date' => $day->toDateString(),
                'from_currency' => $from,
                'to_currency' => $to,
            ],
            [
                'rate' => $rate,
                'fetched_at' => now(),
            ],
        );
    }

    private function fetchRateFromApi(string $from, string $to, Carbon $day): ?float
    {
        try {
            $response = Http::connectTimeout(3)
                ->timeout(15)
                ->get(self::BASE_URL.'/'.$day->toDateString(), [
                    'base' => $from,
                    'symbols' => $to,
                ]);

            return $this->rateFromResponse($response, $to);
        } catch (\Throwable) {
            return null;
        }
    }

    private function rateFromResponse(mixed $response, string $to): ?float
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $rate = $response->json('rates.'.$to);

        return is_numeric($rate) ? (float) $rate : null;
    }

    private function isCacheStale(?Carbon $fetchedAt, Carbon $rateDate): bool
    {
        if ($fetchedAt === null) {
            return true;
        }

        $today = now()->startOfDay();
        if (! $rateDate->isSameDay($today)) {
            return false;
        }

        return $fetchedAt->diffInHours(now()) > self::CACHE_TTL_HOURS;
    }

    private function rateKey(Carbon $day, string $from): string
    {
        return $day->toDateString().'|'.$from;
    }
}
