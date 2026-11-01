<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentIdentity;
use App\Services\FilingEngine;
use App\Services\GeminiDocumentAnalyzer;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentDisplayConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_locked_invoice_amount_overrides_ai_amount_due_in_api_and_facts(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'document_type' => 'Invoice',
            'merchant' => 'Acme',
            'amount' => '600.00',
            'currency' => 'MYR',
            'extracted_data' => [
                'amounts' => [[
                    'value' => '600.00',
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

        $this->actingAs($user)->patchJson('/api/documents/'.$document->id, [
            'amount' => '650.00',
        ])->assertOk()
            ->assertJsonPath('document.display_amount', 'MYR 650.00')
            ->assertJsonPath('document.display_amount_label', 'Invoice Total');

        $document->refresh();
        $this->assertFalse($document->extracted_data['interpretation']['expense_eligible'] ?? true);

        $facts = app(DocumentIdentity::class)->facts($document);
        $amountDue = collect($facts)->firstWhere('label', 'Amount due');
        $this->assertSame('MYR 650.00', $amountDue['value'] ?? null);
        $this->assertFalse(
            collect($facts)->contains(fn (array $fact) => str_contains($fact['value'], '600')),
        );
    }

    public function test_locked_document_date_matches_facts_and_column(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'document_type' => 'Receipt',
            'merchant' => 'Shell',
            'document_date' => '2026-01-01',
            'amount' => '40.00',
            'currency' => 'MYR',
            'extracted_data' => [
                'dates' => [[
                    'value' => '2026-01-01',
                    'role' => 'transaction',
                    'uncertain' => false,
                ]],
            ],
        ]);

        $response = $this->actingAs($user)->patchJson('/api/documents/'.$document->id, [
            'document_date' => '2026-03-15',
        ])->assertOk();

        $this->assertStringStartsWith('2026-03-15', (string) $response->json('document.document_date'));

        $document->refresh();
        $this->assertSame('2026-03-15', $document->document_date?->format('Y-m-d'));

        $facts = app(DocumentIdentity::class)->facts($document);
        $transactionDate = collect($facts)->firstWhere('label', 'Transaction date');
        $this->assertSame('15 Mar 2026', $transactionDate['value'] ?? null);
    }

    public function test_insurance_premium_correction_keeps_coverage_fact(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'document_type' => 'Insurance Policy',
            'merchant' => 'Great Eastern',
            'amount' => '1200.00',
            'currency' => 'MYR',
            'extracted_data' => [
                'insured' => 'Toyota Vios',
                'amounts' => [
                    [
                        'value' => '1200.00',
                        'currency' => 'MYR',
                        'role' => 'premium',
                        'payment_status' => 'unknown',
                        'uncertain' => false,
                    ],
                    [
                        'value' => '50000.00',
                        'currency' => 'MYR',
                        'role' => 'coverage',
                        'payment_status' => null,
                        'uncertain' => false,
                    ],
                ],
                'interpretation' => [
                    'primary_amount_role' => 'premium',
                    'expense_eligible' => false,
                ],
            ],
        ]);

        $facts = app(DocumentIdentity::class)->facts($document);
        $this->assertSame('MYR 1,200.00', collect($facts)->firstWhere('label', 'Premium')['value'] ?? null);
        $this->assertSame('MYR 50,000.00', collect($facts)->firstWhere('label', 'Coverage')['value'] ?? null);

        $this->actingAs($user)->patchJson('/api/documents/'.$document->id, [
            'amount' => '1350.00',
        ])->assertOk()
            ->assertJsonPath('document.display_amount', 'MYR 1,350.00')
            ->assertJsonPath('document.display_amount_label', 'Premium');

        $document->refresh();
        $facts = app(DocumentIdentity::class)->facts($document);
        $this->assertSame('MYR 1,350.00', collect($facts)->firstWhere('label', 'Premium')['value'] ?? null);
        $this->assertSame('MYR 50,000.00', collect($facts)->firstWhere('label', 'Coverage')['value'] ?? null);
    }

    public function test_uncertain_expiry_is_omitted_from_display_facts(): void
    {
        $document = $this->makeDocument([
            'document_type' => 'Insurance Policy',
            'merchant' => 'Insurer',
            'extracted_data' => [
                'dates' => [[
                    'value' => '2027-01-01',
                    'role' => 'expiry',
                    'uncertain' => true,
                ]],
            ],
        ]);

        $facts = app(DocumentIdentity::class)->facts($document);
        $this->assertNull(collect($facts)->firstWhere('label', 'Expiry'));
    }

    public function test_legacy_amount_only_document_uses_neutral_amount_label(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'document_type' => 'General Document',
            'amount' => '25.00',
            'currency' => 'MYR',
            'extracted_data' => [],
        ]);

        $this->actingAs($user)
            ->getJson('/api/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('document.display_amount', 'MYR 25.00')
            ->assertJsonPath('document.display_amount_label', 'Amount');
    }

    public function test_reanalysis_does_not_overwrite_locked_amount_or_date(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'document_type' => 'Receipt',
            'merchant' => 'Old Shop',
            'amount' => '10.00',
            'currency' => 'MYR',
            'document_date' => '2026-01-01',
        ]);

        $this->actingAs($user)->patchJson('/api/documents/'.$document->id, [
            'amount' => '99.00',
            'document_date' => '2026-06-15',
        ])->assertOk();

        $document->refresh();
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
        $this->assertSame('2026-06-15', $document->document_date?->format('Y-m-d'));
        $this->assertSame('New Shop', $document->merchant);
        $this->assertFalse($document->extracted_data['interpretation']['expense_eligible'] ?? true);
        $this->assertContains('amount', $document->extracted_data['_user']['locked_fields'] ?? []);
        $this->assertContains('document_date', $document->extracted_data['_user']['locked_fields'] ?? []);
    }

    public function test_receipt_with_clear_paid_exposes_display_amount_and_label(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'document_type' => 'Receipt',
            'merchant' => 'Shell',
            'amount' => '40.50',
            'currency' => 'MYR',
            'extracted_data' => [
                'amounts' => [[
                    'value' => '40.50',
                    'currency' => 'MYR',
                    'role' => 'paid',
                    'payment_status' => 'evidence_present',
                    'uncertain' => false,
                ]],
                'interpretation' => [
                    'primary_amount_role' => 'paid',
                    'expense_eligible' => true,
                ],
            ],
        ]);

        $this->actingAs($user)
            ->getJson('/api/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('document.display_amount', 'MYR 40.50')
            ->assertJsonPath('document.display_amount_label', 'Total Paid');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDocument(array $overrides): Document
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        return Document::factory()->create(array_merge([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
        ], $overrides));
    }
}
