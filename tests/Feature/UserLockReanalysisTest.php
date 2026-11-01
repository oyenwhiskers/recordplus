<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Services\FilingEngine;
use App\Services\GeminiDocumentAnalyzer;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLockReanalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_edited_amount_survives_reanalysis(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'amount' => '10.00',
            'currency' => 'MYR',
            'merchant' => 'Old Shop',
        ]);

        $this->actingAs($user)->patchJson('/api/documents/'.$document->id, [
            'amount' => '99.00',
        ])->assertOk();

        $document->refresh();
        $this->assertSame('99.00', (string) $document->amount);
        $this->assertContains('amount', $document->extracted_data['_user']['locked_fields'] ?? []);

        $extraction = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Receipt',
            'merchant' => 'New Shop',
            'amounts' => [[
                'value' => 1,
                'currency' => 'MYR',
                'role' => 'paid',
                'payment_status' => 'evidence_present',
                'uncertain' => false,
            ]],
            'dates' => [[
                'value' => '2026-09-01',
                'role' => 'transaction',
                'uncertain' => false,
            ]],
        ], []);

        app(FilingEngine::class)->applyExtraction($document, $extraction);
        $document->refresh();

        $this->assertSame('99.00', (string) $document->amount);
        $this->assertSame('New Shop', $document->merchant);
        $this->assertNotEmpty($document->extracted_data['amounts'] ?? []);
        $this->assertContains('amount', $document->extracted_data['_user']['locked_fields'] ?? []);
    }

    public function test_confirmed_filing_survives_reanalysis(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);
        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'merchant' => 'Acme',
            'document_type' => 'Invoice',
        ]);

        $this->actingAs($user)->postJson('/api/documents/'.$document->id.'/confirm', [
            'category_id' => $expenses->id,
        ])->assertOk();

        $document->refresh();
        $this->assertSame(DocumentStatus::Filed, $document->status);
        $this->assertSame($expenses->id, $document->category_id);
        $this->assertTrue($document->extracted_data['_user']['locked_filing'] ?? false);

        $insurance = $this->categoryFor($profile, 'Insurance');
        $extraction = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Insurance Policy',
            'merchant' => 'Insurer',
            'recommended_category_id' => $insurance->id,
            'amounts' => [[
                'value' => 1200,
                'currency' => 'MYR',
                'role' => 'premium',
                'payment_status' => 'unknown',
                'uncertain' => false,
            ]],
            'dates' => [],
        ], [$insurance->id]);

        $document->forceFill(['status' => DocumentStatus::Processing])->save();
        app(FilingEngine::class)->applyExtraction($document->fresh(), $extraction);
        $document->refresh();

        $this->assertSame($expenses->id, $document->category_id);
        $this->assertSame(DocumentStatus::Filed, $document->status);
        $this->assertSame($profile->id, $document->profile_id);
        $this->assertSame($user->id, $document->user_id);
    }
}
