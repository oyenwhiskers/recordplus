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

class DocumentIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_primary_is_issuer_and_secondary_is_reference(): void
    {
        $document = $this->makeDocument([
            'title' => null,
            'document_type' => 'Invoice',
            'merchant' => 'Acme Sdn Bhd',
            'reference_number' => 'INV-2041',
            'original_filename' => 'scan.pdf',
        ]);

        $identity = app(DocumentIdentity::class);

        $this->assertSame('Acme Sdn Bhd', $identity->primary($document));
        $this->assertSame('INV-2041', $identity->secondary($document));
    }

    public function test_long_digit_reference_is_not_masked(): void
    {
        $document = $this->makeDocument([
            'title' => null,
            'document_type' => 'Invoice',
            'merchant' => 'Sabah Electricity',
            'reference_number' => '172216462',
            'original_filename' => 'bill.pdf',
        ]);

        $identity = app(DocumentIdentity::class);

        $this->assertSame('172216462', $identity->secondary($document));
        $this->assertContains(
            ['label' => 'Reference', 'value' => '172216462'],
            $identity->facts($document),
        );
    }

    public function test_receipt_primary_is_merchant_and_secondary_is_date_and_amount(): void
    {
        $document = $this->makeDocument([
            'title' => 'receipt',
            'document_type' => 'Receipt',
            'merchant' => 'Shell',
            'document_date' => '2026-03-12',
            'amount' => '40.50',
            'currency' => 'MYR',
            'original_filename' => 'receipt.jpg',
        ]);

        $identity = app(DocumentIdentity::class);

        $this->assertSame('Shell', $identity->primary($document));
        $this->assertSame('12 Mar 2026 · MYR 40.50', $identity->secondary($document));
    }

    public function test_insurance_uses_insured_and_policy_line(): void
    {
        $document = $this->makeDocument([
            'title' => null,
            'document_type' => 'Insurance Policy',
            'merchant' => 'Great Eastern',
            'extracted_data' => [
                'insured' => 'Toyota Vios ABC1234',
                'policy_number' => 'POL-99',
            ],
            'original_filename' => 'policy.pdf',
        ]);

        $identity = app(DocumentIdentity::class);

        $this->assertSame('Toyota Vios ABC1234', $identity->primary($document));
        $this->assertSame('Great Eastern · Policy #POL-99', $identity->secondary($document));
    }

    public function test_custom_title_is_primary_and_legacy_ai_title_is_ignored(): void
    {
        $identity = app(DocumentIdentity::class);

        $custom = $this->makeDocument([
            'title' => 'Tax folder — March',
            'document_type' => 'Invoice',
            'merchant' => 'Acme',
            'reference_number' => '1',
            'extracted_data' => ['title' => 'Acme — Invoice — 1'],
            'original_filename' => 'scan.pdf',
        ]);

        $this->assertTrue($identity->isCustomName($custom));
        $this->assertSame('Tax folder — March', $identity->primary($custom));

        $legacy = $this->makeDocument([
            'title' => 'Acme — Invoice — 1',
            'document_type' => 'Invoice',
            'merchant' => 'Acme',
            'reference_number' => '1',
            'extracted_data' => ['title' => 'Acme — Invoice — 1'],
            'original_filename' => 'scan.pdf',
        ]);

        $this->assertFalse($identity->isCustomName($legacy));
        $this->assertSame('Acme', $identity->primary($legacy));
    }

    public function test_falls_back_to_filename_then_document_id(): void
    {
        $identity = app(DocumentIdentity::class);

        $fromFile = $this->makeDocument([
            'title' => null,
            'document_type' => 'General Document',
            'merchant' => null,
            'original_filename' => 'house-deed.pdf',
        ]);

        $this->assertSame('house-deed', $identity->primary($fromFile));

        $bare = $this->makeDocument([
            'title' => null,
            'document_type' => null,
            'merchant' => null,
            'original_filename' => 'document.pdf',
        ]);

        $this->assertSame('Document #'.$bare->id, $identity->primary($bare));
    }

    public function test_sanitize_extraction_always_nulls_title(): void
    {
        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Warranty',
            'title' => 'Should Never Appear',
            'document_date' => '2026-01-01',
            'merchant' => 'Sony',
            'product_name' => 'WH-1000XM5',
            'recommended_category_id' => null,
            'suggested_new_category_name' => null,
        ], []);

        $this->assertNull($result['title']);
        $this->assertSame('Warranty', $result['document_type']);
        $this->assertSame('WH-1000XM5', $result['extracted_data']['product_name'] ?? null);
        $this->assertArrayNotHasKey('title', $result['extracted_data']);
    }

    public function test_apply_extraction_does_not_change_existing_custom_title(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'title' => 'My custom filing label',
            'document_type' => 'Invoice',
        ]);

        app(FilingEngine::class)->applyExtraction($document, [
            'document_type' => 'Invoice',
            'title' => null,
            'document_date' => '2026-04-01',
            'merchant' => 'Acme',
            'reference_number' => 'X-1',
            'amount' => null,
            'currency' => null,
            'extracted_data' => [
                'document_type' => 'Invoice',
                'merchant' => 'Acme',
            ],
        ]);

        $document->refresh();
        $this->assertSame('My custom filing label', $document->title);
        $this->assertSame('My custom filing label', $document->display_primary);
    }

    public function test_api_document_json_includes_display_fields(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'title' => null,
            'document_type' => 'Invoice',
            'merchant' => 'Acme',
            'reference_number' => 'INV-9',
            'original_filename' => 'scan.pdf',
        ]);

        $this->actingAs($user)
            ->getJson('/api/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('document.display_primary', 'Acme')
            ->assertJsonPath('document.display_secondary', 'INV-9');
    }

    public function test_display_amount_uses_commas_and_compacts_large_month_card_values(): void
    {
        $identity = app(DocumentIdentity::class);

        $withCommas = $this->makeDocument([
            'amount' => '12250.00',
            'currency' => 'MYR',
        ]);
        $this->assertSame('MYR 12,250.00', $identity->displayAmount($withCommas));
        $this->assertSame('MYR 12,250.00', $identity->displayAmount($withCommas, compact: true));

        $hundredK = $this->makeDocument([
            'amount' => '100000.00',
            'currency' => 'MYR',
        ]);
        $this->assertSame('MYR 100,000.00', $identity->displayAmount($hundredK));
        $this->assertSame('MYR 100K', $identity->displayAmount($hundredK, compact: true));

        $oneM = $this->makeDocument([
            'amount' => '1000000.00',
            'currency' => 'MYR',
        ]);
        $this->assertSame('MYR 1,000,000.00', $identity->displayAmount($oneM));
        $this->assertSame('MYR 1M', $identity->displayAmount($oneM, compact: true));

        $fiftyM = $this->makeDocument([
            'amount' => '50000000.00',
            'currency' => 'MYR',
        ]);
        $this->assertSame('MYR 50M', $identity->displayAmount($fiftyM, compact: true));
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
