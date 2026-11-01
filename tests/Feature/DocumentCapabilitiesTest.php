<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentCapabilities;
use App\Services\DocumentIdentity;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_document_with_expiry_exposes_capability_and_display(): void
    {
        $document = $this->makeDocument([
            'document_type' => 'General Document',
            'merchant' => 'JPJ',
            'status' => DocumentStatus::NeedsReview,
            'extracted_data' => [
                'dates' => [[
                    'value' => '2027-06-01',
                    'role' => 'expiry',
                    'uncertain' => false,
                ]],
            ],
        ]);

        $caps = app(DocumentCapabilities::class)->for($document);
        $this->assertTrue($caps['has_expiry']);
        $this->assertSame('2027-06-01', $caps['expiry_date']);
        $this->assertSame('expiry', $caps['expiry_role']);
        $this->assertFalse($caps['has_money']);

        $facts = app(DocumentIdentity::class)->facts($document);
        $this->assertSame('1 Jun 2027', collect($facts)->firstWhere('label', 'Expiry')['value'] ?? null);

        $secondary = app(DocumentIdentity::class)->secondary($document);
        $this->assertNotNull($secondary);
        $this->assertStringContainsString('Expires 1 Jun 2027', $secondary);
    }

    public function test_uncertain_expiry_does_not_enable_capability(): void
    {
        $document = $this->makeDocument([
            'document_type' => 'Warranty',
            'extracted_data' => [
                'dates' => [[
                    'value' => '2028-01-01',
                    'role' => 'expiry',
                    'uncertain' => true,
                ]],
            ],
        ]);

        $caps = app(DocumentCapabilities::class)->for($document);
        $this->assertFalse($caps['has_expiry']);
        $this->assertNull($caps['expiry_date']);
    }

    public function test_receipt_with_amount_has_money_capability(): void
    {
        $document = $this->makeDocument([
            'document_type' => 'Receipt',
            'amount' => '12.00',
            'currency' => 'MYR',
            'extracted_data' => [
                'amounts' => [[
                    'value' => '12.00',
                    'currency' => 'MYR',
                    'role' => 'paid',
                    'uncertain' => false,
                ]],
                'interpretation' => [
                    'expense_eligible' => true,
                    'primary_amount_role' => 'paid',
                ],
            ],
        ]);

        $caps = app(DocumentCapabilities::class)->for($document);
        $this->assertTrue($caps['has_money']);
        $this->assertTrue($caps['expense_eligible']);
        $this->assertFalse($caps['has_expiry']);
    }

    public function test_capabilities_are_appended_on_api_show(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'document_type' => 'Insurance Policy',
            'amount' => '100.00',
            'currency' => 'MYR',
            'extracted_data' => [
                'dates' => [[
                    'value' => '2027-01-01',
                    'role' => 'expiry',
                    'uncertain' => false,
                ]],
                'amounts' => [
                    [
                        'value' => '100.00',
                        'currency' => 'MYR',
                        'role' => 'premium',
                        'uncertain' => false,
                    ],
                    [
                        'value' => '50000.00',
                        'currency' => 'MYR',
                        'role' => 'coverage',
                        'uncertain' => false,
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->getJson('/api/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('document.capabilities.has_expiry', true)
            ->assertJsonPath('document.capabilities.has_money', true)
            ->assertJsonPath('document.capabilities.has_coverage', true);
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
