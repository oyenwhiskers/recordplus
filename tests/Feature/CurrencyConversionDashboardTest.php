<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrencyConversionDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_converts_mixed_currencies_into_base(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => '2026-03-15',
                'rates' => ['MYR' => 4.0],
            ], 200),
        ]);

        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'status' => DocumentStatus::Filed,
            'document_date' => '2026-03-15',
            'amount' => '10.00',
            'currency' => 'MYR',
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'status' => DocumentStatus::Filed,
            'document_date' => '2026-03-15',
            'amount' => '5.00',
            'currency' => 'USD',
        ]);

        $response = $this->actingAs($user)->getJson('/api/dashboard?year=2026');

        $response->assertOk();
        $byName = collect($response->json('categories'))->keyBy('name');
        // 10 MYR + (5 USD * 4) = 30 MYR
        $this->assertSame('MYR 30.00', $byName['Expenses']['display_amount'] ?? null);
    }

    public function test_date_facets_sum_multi_document_months_in_base(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => '2026-10-01',
                'rates' => ['MYR' => 4.0],
            ], 200),
        ]);

        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'document_date' => '2026-10-01',
            'amount' => '10.00',
            'currency' => 'MYR',
            'status' => DocumentStatus::Filed,
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'document_date' => '2026-10-15',
            'amount' => '5.00',
            'currency' => 'USD',
            'status' => DocumentStatus::Filed,
        ]);

        $facets = $this->actingAs($user)->getJson('/api/documents/date-facets?category_id='.$expenses->id);
        $facets->assertOk();

        $months = collect(collect($facets->json('years'))->firstWhere('year', 2026)['months'] ?? [])->keyBy('month');
        $this->assertSame(2, $months[10]['total']);
        $this->assertSame('MYR 30.00', $months[10]['display_amount']);
        $this->assertNull($months[10]['display_amount_label']);
    }

    public function test_dashboard_totals_follow_updated_base_currency(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response([
                'amount' => 1.0,
                'base' => 'MYR',
                'date' => '2026-03-15',
                'rates' => ['SGD' => 0.3],
            ], 200),
        ]);

        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $profile->update(['base_currency' => 'SGD']);
        $expenses = $this->categoryFor($profile);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'status' => DocumentStatus::Filed,
            'document_date' => '2026-03-15',
            'amount' => '100.00',
            'currency' => 'MYR',
        ]);

        $response = $this->actingAs($user)->getJson('/api/dashboard?year=2026');
        $response->assertOk();

        $byName = collect($response->json('categories'))->keyBy('name');
        $this->assertSame('SGD 30.00', $byName['Expenses']['display_amount'] ?? null);
    }
}
