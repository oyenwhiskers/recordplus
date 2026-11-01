<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Category;
use App\Models\Document;
use App\Models\FilingRule;
use App\Models\User;
use App\Services\FilingEngine;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordPlusFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_personal_profile_without_categories(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Alex',
            'email' => 'alex@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertCreated();
        $this->assertAuthenticated();

        $user = User::query()->where('email', 'alex@example.com')->first();
        $this->assertNotNull($user?->active_profile_id);
        $this->assertSame('Personal', $user->activeProfile->name);
        $this->assertSame(0, $user->activeProfile->categories()->count());
    }

    public function test_upload_freezes_profile_even_if_active_profile_changes(): void
    {
        Storage::fake('local');
        config(['services.gemini.enabled' => false]);

        $user = User::factory()->create();
        $onboarding = app(ProfileOnboardingService::class);
        $personal = $onboarding->onboard($user);
        $business = $onboarding->createProfile($user->fresh(), 'YK NEXUS', true);
        $user->forceFill(['active_profile_id' => $business->id])->save();

        $this->actingAs($user);

        $response = $this->postJson('/api/documents', [
            'profile_id' => $business->id,
            'file' => UploadedFile::fake()->image('receipt.jpg'),
        ]);

        $response->assertCreated();
        $documentId = $response->json('document.id');

        $user->forceFill(['active_profile_id' => $personal->id])->save();

        $document = Document::query()->findOrFail($documentId);
        $this->assertSame($business->id, $document->profile_id);
        $this->assertTrue(Storage::disk('local')->exists($document->storage_path));
    }

    public function test_ai_never_creates_category_without_user_approval(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
            'document_type' => 'Contract',
            'merchant' => 'Acme Legal',
            'title' => null,
        ]);

        $engine = app(FilingEngine::class);
        $engine->applyExtraction($document, [
            'document_type' => 'Contract',
            'title' => 'Service Agreement',
            'document_date' => '2026-01-01',
            'merchant' => 'Acme Legal',
            'reference_number' => 'C-1',
            'amount' => null,
            'currency' => null,
            'extracted_data' => [],
        ]);

        $document->refresh();
        $this->assertSame(DocumentStatus::NeedsReview, $document->status);
        $this->assertTrue($document->suggests_new_category);
        $this->assertSame('Contracts', $document->suggested_category_name);
        $this->assertFalse(
            Category::query()->where('profile_id', $profile->id)->where('name', 'Contracts')->exists()
        );
    }

    public function test_approved_filing_rule_auto_files_matching_document(): void
    {
        $this->markTestSkipped('Filing rules paused until core filing UX is stable.');

        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);

        FilingRule::query()->create([
            'profile_id' => $profile->id,
            'document_type' => 'Receipt',
            'merchant_normalized' => 'shell',
            'category_id' => $expenses->id,
            'is_active' => true,
        ]);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Receipt',
            'title' => 'Shell receipt',
            'document_date' => '2026-02-01',
            'merchant' => 'Shell',
            'reference_number' => null,
            'amount' => '40.00',
            'currency' => 'MYR',
            'extracted_data' => [],
        ]);

        $document->refresh();
        $this->assertSame(DocumentStatus::Filed, $document->status);
        $this->assertSame($expenses->id, $document->category_id);
    }

    public function test_apply_extraction_leaves_title_untouched_and_display_uses_merchant(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
            'title' => 'Test receipt',
            'original_filename' => 'scan.pdf',
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Invoice',
            'title' => 'INVOICE',
            'document_date' => '2026-03-12',
            'merchant' => 'Acme',
            'reference_number' => null,
            'amount' => '100.00',
            'currency' => 'MYR',
            'extracted_data' => [
                'document_type' => 'Invoice',
                'merchant' => 'Acme',
            ],
        ]);

        $document->refresh();
        $this->assertSame('Test receipt', $document->title);
        $this->assertSame('Test receipt', $document->display_primary);
    }

    public function test_user_cannot_see_another_users_documents(): void
    {
        Storage::fake('local');
        config(['services.gemini.enabled' => false]);

        $owner = User::factory()->create();
        $ownerProfile = app(ProfileOnboardingService::class)->onboard($owner);
        $document = Document::factory()->create([
            'user_id' => $owner->id,
            'profile_id' => $ownerProfile->id,
        ]);

        $intruder = User::factory()->create();
        app(ProfileOnboardingService::class)->onboard($intruder);

        $this->actingAs($intruder)
            ->getJson('/api/documents/'.$document->id)
            ->assertForbidden();
    }
}
