<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Category;
use App\Models\Document;
use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyFilesExplorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_index_includes_profile_scoped_counts(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);

        Document::factory()->count(2)->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'status' => DocumentStatus::Filed,
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => null,
            'status' => DocumentStatus::NeedsReview,
        ]);

        $other = User::factory()->create();
        $otherProfile = app(ProfileOnboardingService::class)->onboard($other);
        Document::factory()->create([
            'user_id' => $other->id,
            'profile_id' => $otherProfile->id,
            'status' => DocumentStatus::NeedsReview,
        ]);

        $response = $this->actingAs($user)->getJson('/api/categories');

        $response->assertOk()
            ->assertJsonPath('counts.all', 3)
            ->assertJsonPath('counts.needs_review', 1);

        $expensesPayload = collect($response->json('categories'))->firstWhere('name', 'Expenses');
        $this->assertSame(2, $expensesPayload['documents_count']);
    }

    public function test_documents_index_supports_sort_and_date_search(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $older = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'title' => 'Alpha Doc',
            'merchant' => 'Alpha',
            'document_date' => '2026-01-01',
            'status' => DocumentStatus::Filed,
        ]);

        $newer = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'title' => 'Beta Doc',
            'merchant' => 'Beta',
            'document_date' => '2026-09-18',
            'status' => DocumentStatus::Filed,
        ]);

        $newestFirst = $this->actingAs($user)->getJson('/api/documents?sort=newest');
        $newestFirst->assertOk();
        $this->assertSame($newer->id, $newestFirst->json('documents.data.0.id'));

        $nameAsc = $this->actingAs($user)->getJson('/api/documents?sort=name_asc');
        $nameAsc->assertOk();
        $this->assertSame($older->id, $nameAsc->json('documents.data.0.id'));

        $this->actingAs($user)
            ->getJson('/api/documents?q=2026-09-18')
            ->assertOk()
            ->assertJsonPath('documents.total', 1)
            ->assertJsonPath('documents.data.0.id', $newer->id);

        $this->actingAs($user)
            ->getJson('/api/documents?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('documents.total', 1)
            ->assertJsonPath('documents.data.0.id', $newer->id);

        $this->actingAs($user)
            ->getJson('/api/documents?date_from=2026-10-01&date_to=2026-01-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_category_name_must_be_unique_within_profile(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $this->categoryFor($profile);

        $this->actingAs($user)
            ->postJson('/api/categories', ['name' => 'Expenses'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($user)
            ->postJson('/api/categories', ['name' => 'Travel'])
            ->assertCreated();
    }

    public function test_documents_index_supports_year_month_and_undated_filters(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);

        $september = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'document_date' => '2026-09-18',
            'status' => DocumentStatus::Filed,
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'document_date' => '2026-10-01',
            'status' => DocumentStatus::Filed,
        ]);

        $undated = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'document_date' => null,
            'status' => DocumentStatus::Filed,
        ]);

        $this->actingAs($user)
            ->getJson('/api/documents?category_id='.$expenses->id.'&year=2026&month=9')
            ->assertOk()
            ->assertJsonPath('documents.total', 1)
            ->assertJsonPath('documents.data.0.id', $september->id);

        $this->actingAs($user)
            ->getJson('/api/documents?category_id='.$expenses->id.'&undated=1')
            ->assertOk()
            ->assertJsonPath('documents.total', 1)
            ->assertJsonPath('documents.data.0.id', $undated->id);

        $facets = $this->actingAs($user)->getJson('/api/documents/date-facets?category_id='.$expenses->id);
        $facets->assertOk()
            ->assertJsonPath('undated', 1);

        $year2026 = collect($facets->json('years'))->firstWhere('year', 2026);
        $this->assertNotNull($year2026);
        $this->assertSame(2, $year2026['total']);
        $months = collect($year2026['months'])->keyBy('month');
        $this->assertSame(1, $months[9]['total']);
        $this->assertSame(1, $months[10]['total']);
        $this->assertNull($months[9]['display_amount'] ?? null);
        $this->assertNull($months[10]['display_amount'] ?? null);
    }

    public function test_date_facets_expose_single_and_multi_document_month_amounts(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'document_date' => '2026-09-18',
            'document_type' => 'Invoice',
            'amount' => '662.00',
            'currency' => 'MYR',
            'status' => DocumentStatus::Filed,
            'extracted_data' => [
                'amounts' => [[
                    'value' => '662.00',
                    'currency' => 'MYR',
                    'role' => 'total_due',
                    'payment_status' => 'unpaid',
                    'uncertain' => false,
                ]],
                'interpretation' => [
                    'primary_amount_role' => 'total_due',
                    'expense_eligible' => false,
                ],
            ],
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'document_date' => '2026-10-01',
            'document_type' => 'Receipt',
            'amount' => '25.00',
            'currency' => 'MYR',
            'status' => DocumentStatus::Filed,
            'extracted_data' => [
                'amounts' => [[
                    'value' => '25.00',
                    'currency' => 'MYR',
                    'role' => 'paid',
                    'uncertain' => false,
                ]],
                'interpretation' => [
                    'primary_amount_role' => 'paid',
                    'expense_eligible' => true,
                ],
            ],
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'document_date' => '2026-10-15',
            'document_type' => 'Receipt',
            'amount' => '40.00',
            'currency' => 'MYR',
            'status' => DocumentStatus::Filed,
            'extracted_data' => [
                'amounts' => [[
                    'value' => '40.00',
                    'currency' => 'MYR',
                    'role' => 'paid',
                    'uncertain' => false,
                ]],
                'interpretation' => [
                    'primary_amount_role' => 'paid',
                    'expense_eligible' => true,
                ],
            ],
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'document_date' => '2026-11-02',
            'document_type' => 'Invoice',
            'amount' => '0.00',
            'currency' => 'MYR',
            'status' => DocumentStatus::Filed,
            'extracted_data' => [
                'interpretation' => [
                    'primary_amount_role' => 'total_due',
                ],
            ],
        ]);

        $facets = $this->actingAs($user)->getJson('/api/documents/date-facets?category_id='.$expenses->id);
        $facets->assertOk();

        $year2026 = collect($facets->json('years'))->firstWhere('year', 2026);
        $this->assertNotNull($year2026);
        $months = collect($year2026['months'])->keyBy('month');

        $this->assertSame(1, $months[9]['total']);
        $this->assertSame('MYR 662.00', $months[9]['display_amount']);
        $this->assertSame('Invoice Total', $months[9]['display_amount_label']);

        $this->assertSame(2, $months[10]['total']);
        $this->assertSame('MYR 65.00', $months[10]['display_amount']);
        $this->assertNull($months[10]['display_amount_label']);

        $this->assertSame(1, $months[11]['total']);
        $this->assertNull($months[11]['display_amount']);
        $this->assertNull($months[11]['display_amount_label']);
    }

    public function test_date_facets_compact_large_single_document_month_amounts(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'document_date' => '2026-09-18',
            'document_type' => 'Invoice',
            'amount' => '12250.00',
            'currency' => 'MYR',
            'status' => DocumentStatus::Filed,
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'document_date' => '2026-10-18',
            'document_type' => 'Invoice',
            'amount' => '100000.00',
            'currency' => 'MYR',
            'status' => DocumentStatus::Filed,
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'document_date' => '2026-11-18',
            'document_type' => 'Invoice',
            'amount' => '50000000.00',
            'currency' => 'MYR',
            'status' => DocumentStatus::Filed,
        ]);

        $facets = $this->actingAs($user)->getJson('/api/documents/date-facets?category_id='.$expenses->id);
        $facets->assertOk();

        $months = collect(collect($facets->json('years'))->firstWhere('year', 2026)['months'] ?? [])->keyBy('month');

        $this->assertSame('MYR 12,250.00', $months[9]['display_amount']);
        $this->assertSame('MYR 100K', $months[10]['display_amount']);
        $this->assertSame('MYR 50M', $months[11]['display_amount']);
    }

    public function test_deleting_category_can_reassign_documents(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $source = Category::query()->create([
            'profile_id' => $profile->id,
            'name' => 'To Delete',
        ]);
        $target = $this->categoryFor($profile);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $source->id,
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/categories/'.$source->id, [
                'reassign_to_category_id' => $target->id,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $source->id]);
        $this->assertSame($target->id, $document->fresh()->category_id);
    }
}
