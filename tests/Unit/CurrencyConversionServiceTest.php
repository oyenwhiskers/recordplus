<?php

namespace Tests\Unit;

use App\Services\CurrencyConversionService;
use App\Support\CurrencyOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrencyConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetches_live_frankfurter_url_shape_matching_mobile(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => '2026-03-13',
                'rates' => ['MYR' => 3.9385],
            ], 200),
        ]);

        $rate = app(CurrencyConversionService::class)->getRate('USD', 'MYR', '2026-03-15');

        $this->assertEqualsWithDelta(3.9385, $rate, 0.0001);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.frankfurter.dev/v1/2026-03-15')
                && $request['base'] === 'USD'
                && $request['symbols'] === 'MYR';
        });
    }

    public function test_all_allowlisted_base_currencies_can_convert_from_usd(): void
    {
        Http::fake(function ($request) {
            $to = $request['symbols'] ?? 'MYR';

            return Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => '2026-03-13',
                'rates' => [$to => 2.5],
            ], 200);
        });

        $service = app(CurrencyConversionService::class);

        foreach (CurrencyOptions::CODES as $code) {
            if ($code === 'USD') {
                $this->assertSame(1.0, $service->getRate('USD', 'USD', '2026-03-15'));

                continue;
            }

            $rate = $service->getRate('USD', $code, '2026-03-15');
            $this->assertNotNull($rate, "missing rate for USD->{$code}");
            $this->assertEqualsWithDelta(2.5, $rate, 0.0001);
        }
    }

    public function test_prefetch_batches_distinct_pairs_into_base(): void
    {
        Http::fake(function ($request) {
            $from = $request['base'] ?? 'USD';
            $to = $request['symbols'] ?? 'MYR';
            $rates = [
                'USD' => 4.0,
                'SGD' => 3.0,
                'EUR' => 4.5,
            ];

            return Http::response([
                'amount' => 1.0,
                'base' => $from,
                'date' => '2026-03-13',
                'rates' => [$to => $rates[$from] ?? 1.0],
            ], 200);
        });

        $map = app(CurrencyConversionService::class)->prefetchRates([
            ['date' => '2026-03-15', 'from' => 'USD'],
            ['date' => '2026-03-15', 'from' => 'SGD'],
            ['date' => '2026-03-16', 'from' => 'EUR'],
            ['date' => '2026-03-15', 'from' => 'USD'],
        ], 'MYR');

        $this->assertSame(4.0, $map['2026-03-15|USD']);
        $this->assertSame(3.0, $map['2026-03-15|SGD']);
        $this->assertSame(4.5, $map['2026-03-16|EUR']);
        $this->assertCount(3, $map);
    }
}
