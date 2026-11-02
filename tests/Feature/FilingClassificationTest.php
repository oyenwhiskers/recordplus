<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Category;
use App\Models\Document;
use App\Models\FilingPreference;
use App\Models\User;
use App\Services\FilingEngine;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilingClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payslip_contextual_fallback_suggests_employment_not_expenses(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $this->categoryFor($profile, 'Expenses');

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Payslip',
            'title' => null,
            'document_date' => '2026-03-01',
            'merchant' => 'Acme Corp',
            'reference_number' => null,
            'amount' => '3000.00',
            'currency' => 'MYR',
            'recommended_category_id' => null,
            'suggested_new_category_name' => null,
            'filing_context' => 'employment_income',
            'extracted_data' => [
                'filing_context' => 'employment_income',
            ],
        ]);

        $document->refresh();
        $this->assertSame(DocumentStatus::NeedsReview, $document->status);
        $this->assertNull($document->category_id);
        $this->assertTrue($document->suggests_new_category);
        $this->assertSame('Employment', $document->suggested_category_name);
    }

    public function test_customer_sale_invoice_suggests_sales_not_expenses(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $this->categoryFor($profile, 'Expenses');

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Invoice',
            'title' => null,
            'document_date' => '2026-03-01',
            'merchant' => 'Client Co',
            'reference_number' => 'INV-9',
            'amount' => '500.00',
            'currency' => 'MYR',
            'recommended_category_id' => null,
            'suggested_new_category_name' => null,
            'filing_context' => 'customer_sale',
            'extracted_data' => [
                'filing_context' => 'customer_sale',
            ],
        ]);

        $document->refresh();
        $this->assertTrue($document->suggests_new_category);
        $this->assertSame('Sales', $document->suggested_category_name);
    }

    public function test_fuel_receipt_supplier_purchase_reuses_expenses_not_vehicles(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile, 'Expenses');
        $this->categoryFor($profile, 'Vehicles');

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Receipt',
            'title' => null,
            'document_date' => '2026-10-01',
            'merchant' => 'Shell',
            'reference_number' => null,
            'amount' => '60.00',
            'currency' => 'MYR',
            'recommended_category_id' => null,
            'suggested_new_category_name' => null,
            'filing_context' => 'supplier_purchase',
            'extracted_data' => [
                'filing_context' => 'supplier_purchase',
            ],
        ]);

        $document->refresh();
        $this->assertSame(DocumentStatus::NeedsReview, $document->status);
        $this->assertNull($document->category_id);
        $this->assertFalse($document->suggests_new_category);
        $this->assertSame($expenses->name, $document->suggested_category_name);
    }

    public function test_synonym_reuses_existing_vehicles_category(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $vehicles = $this->categoryFor($profile, 'Vehicles');

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'General Document',
            'title' => null,
            'document_date' => '2026-03-01',
            'merchant' => 'JPJ',
            'reference_number' => null,
            'amount' => null,
            'currency' => null,
            'recommended_category_id' => null,
            'suggested_new_category_name' => 'Vehicle',
            'filing_context' => 'vehicle',
            'extracted_data' => [
                'filing_context' => 'vehicle',
            ],
        ]);

        $document->refresh();
        $this->assertFalse($document->suggests_new_category);
        $this->assertSame($vehicles->name, $document->suggested_category_name);
        $this->assertSame(1, Category::query()->where('profile_id', $profile->id)->count());
    }

    public function test_instance_level_new_category_name_is_rejected(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Receipt',
            'title' => null,
            'document_date' => '2026-10-01',
            'merchant' => 'Shell',
            'reference_number' => null,
            'amount' => '60.00',
            'currency' => 'MYR',
            'recommended_category_id' => null,
            'suggested_new_category_name' => 'Shell Receipt October',
            'filing_context' => null,
            'extracted_data' => [],
        ]);

        $document->refresh();
        // Falls through to contextual Receipt -> Purchases.
        $this->assertTrue($document->suggests_new_category);
        $this->assertSame('Purchases', $document->suggested_category_name);
        $this->assertFalse(
            app(FilingEngine::class)->isReusableCategoryName('Shell Receipt October')
        );
        $this->assertFalse(
            app(FilingEngine::class)->isReusableCategoryName('Etiqa Policy 2026')
        );
    }

    public function test_merchant_specific_preference_beats_ai_recommendation(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $vehicles = $this->categoryFor($profile, 'Vehicles');
        $expenses = $this->categoryFor($profile, 'Expenses');

        FilingPreference::query()->create([
            'profile_id' => $profile->id,
            'document_type' => 'Receipt',
            'merchant_normalized' => 'toyota service',
            'category_id' => $vehicles->id,
            'confirmation_count' => 2,
        ]);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
            'merchant' => 'Toyota Service',
            'document_type' => 'Receipt',
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Receipt',
            'title' => null,
            'document_date' => '2026-03-01',
            'merchant' => 'Toyota Service',
            'reference_number' => null,
            'amount' => '120.00',
            'currency' => 'MYR',
            'recommended_category_id' => $expenses->id,
            'suggested_new_category_name' => null,
            'extracted_data' => [],
        ]);

        $document->refresh();
        $this->assertFalse($document->suggests_new_category);
        $this->assertSame('Vehicles', $document->suggested_category_name);
    }

    public function test_weak_type_only_preference_does_not_override_ai_category(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile, 'Expenses');
        $sales = $this->categoryFor($profile, 'Sales');

        FilingPreference::query()->create([
            'profile_id' => $profile->id,
            'document_type' => 'Invoice',
            'merchant_normalized' => null,
            'category_id' => $expenses->id,
            'confirmation_count' => 5,
        ]);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Invoice',
            'title' => null,
            'document_date' => '2026-03-01',
            'merchant' => 'Client Co',
            'reference_number' => null,
            'amount' => null,
            'currency' => null,
            'recommended_category_id' => $sales->id,
            'suggested_new_category_name' => null,
            'extracted_data' => [],
        ]);

        $document->refresh();
        $this->assertFalse($document->suggests_new_category);
        $this->assertSame('Sales', $document->suggested_category_name);
    }

    public function test_locked_filing_preserved_on_reanalysis(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $employment = $this->categoryFor($profile, 'Employment');
        $expenses = $this->categoryFor($profile, 'Expenses');

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'document_type' => 'Payslip',
            'merchant' => 'Acme Corp',
        ]);

        app(FilingEngine::class)->confirmFiling($document, $employment);

        $document->forceFill(['status' => DocumentStatus::Processing])->save();
        app(FilingEngine::class)->applyExtraction($document->fresh(), [
            'document_type' => 'Payslip',
            'title' => null,
            'document_date' => '2026-04-01',
            'merchant' => 'Acme Corp',
            'reference_number' => null,
            'amount' => '3100.00',
            'currency' => 'MYR',
            'recommended_category_id' => $expenses->id,
            'suggested_new_category_name' => 'Expenses',
            'extracted_data' => [],
        ]);

        $document->refresh();
        $this->assertSame(DocumentStatus::Filed, $document->status);
        $this->assertSame($employment->id, $document->category_id);
    }

    public function test_approve_suggested_category_reuses_case_insensitive_name(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $existing = $this->categoryFor($profile, 'Vehicles');

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'document_type' => 'General Document',
            'suggests_new_category' => true,
            'suggested_category_name' => 'vehicles',
        ]);

        app(FilingEngine::class)->approveSuggestedCategory($document, 'vehicles');

        $document->refresh();
        $this->assertSame($existing->id, $document->category_id);
        $this->assertSame(1, Category::query()->where('profile_id', $profile->id)->count());
    }

    public function test_categories_do_not_leak_across_profiles(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $profileA = app(ProfileOnboardingService::class)->onboard($userA);
        $profileB = app(ProfileOnboardingService::class)->onboard($userB);
        $vehiclesA = $this->categoryFor($profileA, 'Vehicles');
        $this->categoryFor($profileB, 'Vehicles');

        $document = Document::factory()->create([
            'user_id' => $userB->id,
            'profile_id' => $profileB->id,
            'status' => DocumentStatus::Processing,
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'General Document',
            'title' => null,
            'document_date' => null,
            'merchant' => 'JPJ',
            'reference_number' => null,
            'amount' => null,
            'currency' => null,
            'recommended_category_id' => $vehiclesA->id,
            'suggested_new_category_name' => null,
            'filing_context' => 'vehicle',
            'extracted_data' => [
                'filing_context' => 'vehicle',
            ],
        ]);

        $document->refresh();
        $this->assertFalse($document->suggests_new_category);
        $this->assertSame('Vehicles', $document->suggested_category_name);
        $this->assertNull($document->category_id);
        $this->assertNotSame($vehiclesA->id, Category::query()
            ->where('profile_id', $profileB->id)
            ->where('name', 'Vehicles')
            ->value('id'));
    }

    public function test_category_api_rejects_case_insensitive_duplicate(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $this->categoryFor($profile, 'Vehicles');

        $this->actingAs($user)
            ->postJson('/api/categories', ['name' => 'vehicles'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(1, Category::query()->where('profile_id', $profile->id)->count());
    }

    public function test_insurance_and_vehicles_are_not_treated_as_synonyms(): void
    {
        $engine = app(FilingEngine::class);
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $vehicles = $this->categoryFor($profile, 'Vehicles');

        $this->assertNull($engine->findExistingCategory($profile->id, 'Insurance'));
        $this->assertSame($vehicles->id, $engine->findExistingCategory($profile->id, 'Car Documents')?->id);
    }
}
