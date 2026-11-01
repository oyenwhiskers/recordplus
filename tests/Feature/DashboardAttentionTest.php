<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAttentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_returns_year_scoped_category_counts(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);
        $insurance = $this->categoryFor($profile, 'Insurance');

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'status' => DocumentStatus::Filed,
            'document_date' => '2026-03-15',
            'amount' => '40.50',
            'currency' => 'MYR',
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'status' => DocumentStatus::Filed,
            'document_date' => '2026-07-01',
            'amount' => '19.50',
            'currency' => 'MYR',
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $insurance->id,
            'status' => DocumentStatus::Filed,
            'document_date' => '2025-11-01',
            'amount' => '1200.00',
            'currency' => 'MYR',
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'document_date' => null,
        ]);

        $response = $this->actingAs($user)->getJson('/api/dashboard?year=2026');

        $response->assertOk()
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('totals.documents', 2)
            ->assertJsonPath('needs_review', 1);

        $this->assertContains(2026, $response->json('years'));
        $this->assertContains(2025, $response->json('years'));

        $byName = collect($response->json('categories'))->keyBy('name');
        $this->assertSame(2, $byName['Expenses']['documents_count'] ?? null);
        $this->assertSame('MYR 60.00', $byName['Expenses']['display_amount'] ?? null);
        $this->assertSame(0, $byName['Insurance']['documents_count'] ?? null);
        $this->assertNull($byName['Insurance']['display_amount'] ?? null);
        $this->assertSame(1, $response->json('totals.categories'));
    }
}
