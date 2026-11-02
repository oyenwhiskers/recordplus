<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\FilingRule;
use App\Models\User;
use App\Services\FilingEngine;
use App\Services\GeminiDocumentAnalyzer;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FilingContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_gemini_recommended_category_suggests_without_auto_filing(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Invoice',
            'title' => 'Acme — Invoice — 1',
            'document_date' => '2026-03-01',
            'merchant' => 'Acme',
            'reference_number' => '1',
            'amount' => '10.00',
            'currency' => 'MYR',
            'recommended_category_id' => $expenses->id,
            'suggested_new_category_name' => null,
            'extracted_data' => [],
        ]);

        $document->refresh();
        $this->assertSame(DocumentStatus::NeedsReview, $document->status);
        $this->assertNull($document->category_id);
        $this->assertFalse($document->suggests_new_category);
        $this->assertSame('Expenses', $document->suggested_category_name);
    }

    public function test_invalid_recommended_category_id_is_ignored(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $this->categoryFor($profile);
        $other = User::factory()->create();
        $otherProfile = app(ProfileOnboardingService::class)->onboard($other);
        $foreign = $this->categoryFor($otherProfile);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Invoice',
            'title' => 'Acme — Invoice',
            'document_date' => '2026-03-01',
            'merchant' => 'Acme',
            'reference_number' => null,
            'amount' => null,
            'currency' => null,
            'recommended_category_id' => $foreign->id,
            'suggested_new_category_name' => null,
            'extracted_data' => [],
        ]);

        $document->refresh();
        $this->assertSame(DocumentStatus::NeedsReview, $document->status);
        $this->assertSame('Expenses', $document->suggested_category_name);
        $this->assertFalse($document->suggests_new_category);
    }

    public function test_active_filing_rule_overrides_gemini_category_recommendation(): void
    {
        $this->markTestSkipped('Filing rules paused until core filing UX is stable.');

        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);
        $other = $this->categoryFor($profile, 'Insurance');

        FilingRule::query()->create([
            'profile_id' => $profile->id,
            'document_type' => 'Invoice',
            'merchant_normalized' => 'acme',
            'category_id' => $expenses->id,
            'is_active' => true,
        ]);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Invoice',
            'title' => 'Acme — Invoice',
            'document_date' => '2026-03-01',
            'merchant' => 'Acme',
            'reference_number' => null,
            'amount' => null,
            'currency' => null,
            'recommended_category_id' => $other->id,
            'suggested_new_category_name' => null,
            'extracted_data' => [],
        ]);

        $document->refresh();
        $this->assertSame(DocumentStatus::Filed, $document->status);
        $this->assertSame($expenses->id, $document->category_id);
    }

    public function test_reanalysis_preserves_user_edited_title(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'title' => 'My custom filing label',
            'document_type' => 'Invoice',
            'extracted_data' => [
                'title' => 'Acme — Invoice — OLD',
                'document_type' => 'Invoice',
                'merchant' => 'Acme',
            ],
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Invoice',
            'title' => 'Brand New AI Title',
            'document_date' => '2026-04-01',
            'merchant' => 'Acme',
            'reference_number' => 'X-1',
            'amount' => null,
            'currency' => null,
            'extracted_data' => [
                'title' => 'Brand New AI Title',
                'document_type' => 'Invoice',
                'merchant' => 'Acme',
            ],
        ]);

        $document->refresh();
        $this->assertSame('My custom filing label', $document->title);
    }

    public function test_sanitize_extraction_rejects_invalid_fields(): void
    {
        $analyzer = app(GeminiDocumentAnalyzer::class);

        $result = $analyzer->sanitizeExtraction([
            'document_type' => 'Not A Real Type',
            'title' => 'OK',
            'document_date' => '31-12-2026',
            'merchant' => 'Acme',
            'reference_number' => null,
            'amount' => 'nope',
            'currency' => 'MYR',
            'recommended_category_id' => 99999,
            'suggested_new_category_name' => 'Should Clear When Id Valid',
            'notes' => null,
        ], [1, 2]);

        $this->assertNull($result['document_type']);
        $this->assertNull($result['title']);
        $this->assertNull($result['document_date']);
        $this->assertNull($result['amount']);
        $this->assertNull($result['recommended_category_id']);
        $this->assertSame('Should Clear When Id Valid', $result['suggested_new_category_name']);
    }

    public function test_sanitize_clears_new_category_when_recommended_id_valid(): void
    {
        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Invoice',
            'title' => 'Acme — Invoice',
            'document_date' => '2026-01-01',
            'merchant' => 'Acme',
            'recommended_category_id' => 2,
            'suggested_new_category_name' => 'Nope',
        ], [1, 2]);

        $this->assertSame(2, $result['recommended_category_id']);
        $this->assertNull($result['suggested_new_category_name']);
    }

    public function test_backfill_titles_command_updates_generic_titles_without_gemini(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'title' => 'INVOICE',
            'document_type' => 'Invoice',
            'merchant' => 'Borneo Food Supply Sdn Bhd',
            'reference_number' => 'BFS-INV-2026-0918-001',
            'document_date' => '2026-09-18',
            'extracted_data' => [
                'document_type' => 'Invoice',
                'title' => 'INVOICE',
                'document_date' => '2026-09-18',
                'merchant' => 'Borneo Food Supply Sdn Bhd',
                'reference_number' => 'BFS-INV-2026-0918-001',
            ],
        ]);

        Artisan::call('documents:backfill-titles');

        $document->refresh();
        $this->assertSame(
            'Borneo Food Supply Sdn Bhd — Invoice — BFS-INV-2026-0918-001',
            $document->title
        );
        Http::assertNothingSent();
    }

    public function test_analyzer_prompt_includes_profile_categories(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/test.pdf', 'pdf-bytes');

        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'storage_path' => 'documents/test.pdf',
            'mime_type' => 'application/pdf',
        ]);

        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'gemini-3.1-flash-lite',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'document_type' => 'Invoice',
                                'title' => 'Acme — Invoice — 1',
                                'document_date' => '2026-01-01',
                                'merchant' => 'Acme',
                                'reference_number' => '1',
                                'amount' => 10,
                                'currency' => 'MYR',
                                'recommended_category_id' => $expenses->id,
                                'suggested_new_category_name' => null,
                                'notes' => null,
                            ]),
                        ]],
                    ],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 100,
                    'candidatesTokenCount' => 40,
                    'totalTokenCount' => 140,
                ],
            ]),
        ]);

        $result = app(GeminiDocumentAnalyzer::class)->analyze($document);

        $this->assertSame($expenses->id, $result['recommended_category_id']);
        $this->assertSame('Invoice', $result['document_type']);
        $this->assertNull($result['title']);

        Http::assertSent(function ($request) use ($profile, $expenses) {
            $body = $request->data();
            $text = $body['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($text, $profile->name)
                && str_contains($text, '"id":'.$expenses->id)
                && str_contains($text, 'Expenses')
                && str_contains($text, 'recommended_category_id')
                && str_contains($text, 'filing_context')
                && str_contains($text, 'Do NOT return a title')
                && str_contains($text, 'Warranty')
                && str_contains($text, 'Academic Certificate')
                && str_contains($text, 'Payment not completed (COD')
                && str_contains($text, 'These are purchase records')
                && str_contains($text, 'defaults undated filings to the upload date')
                && str_contains($text, "Today's date is ")
                && str_contains($text, 'Never invent a year from order/reference number digits')
                && str_contains($text, 'Do not use rating deadlines')
                && str_contains($text, 'Fuel / petrol / diesel')
                && str_contains($text, 'not day-to-day fuel spend');
        });
    }

    public function test_missing_document_date_defaults_to_upload_date(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
            'created_at' => '2026-11-02 03:15:00',
            'document_date' => null,
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Invoice',
            'title' => null,
            'document_date' => null,
            'merchant' => 'boong',
            'reference_number' => '586880737131268060',
            'amount' => '174.35',
            'currency' => 'MYR',
            'recommended_category_id' => null,
            'suggested_new_category_name' => 'Purchases',
            'extracted_data' => [
                'amounts' => [[
                    'value' => '174.35',
                    'currency' => 'MYR',
                    'role' => 'total_due',
                    'payment_status' => 'unpaid',
                    'uncertain' => false,
                ]],
                'dates' => [],
                'interpretation' => [
                    'has_structured_money_dates' => true,
                    'expense_eligible' => false,
                    'primary_amount_role' => 'total_due',
                    'primary_date_role' => null,
                ],
            ],
        ]);

        $document->refresh();
        $this->assertSame('2026-11-02', $document->document_date?->format('Y-m-d'));
        $this->assertSame('boong', $document->merchant);
        $this->assertSame('586880737131268060', $document->reference_number);
        $this->assertSame('174.35', (string) $document->amount);
    }

    public function test_sanitize_extraction_keeps_valid_filing_context(): void
    {
        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Payslip',
            'merchant' => 'Acme Corp',
            'filing_context' => 'employment_income',
            'recommended_category_id' => null,
            'suggested_new_category_name' => 'Employment',
            'amounts' => [],
            'dates' => [],
        ], []);

        $this->assertSame('employment_income', $result['filing_context']);
        $this->assertSame('employment_income', $result['extracted_data']['filing_context'] ?? null);
    }

    public function test_sanitize_extraction_rejects_unknown_filing_context(): void
    {
        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Invoice',
            'merchant' => 'Acme',
            'filing_context' => 'not_a_real_context',
            'recommended_category_id' => null,
            'suggested_new_category_name' => null,
            'amounts' => [],
            'dates' => [],
        ], []);

        $this->assertNull($result['filing_context']);
        $this->assertArrayNotHasKey('filing_context', $result['extracted_data']);
    }
}
