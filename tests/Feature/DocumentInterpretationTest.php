<?php

namespace Tests\Feature;

use App\Services\DocumentInterpreter;
use App\Services\GeminiDocumentAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentInterpretationTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_paid_amount_is_expense_eligible_with_evidence(): void
    {
        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Receipt',
            'merchant' => 'Store',
            'amounts' => [[
                'value' => 150,
                'currency' => 'MYR',
                'role' => 'paid',
                'payment_status' => 'evidence_present',
                'uncertain' => false,
            ]],
            'dates' => [[
                'value' => '2026-09-18',
                'role' => 'transaction',
                'uncertain' => false,
            ]],
        ], []);

        $this->assertSame('150', $result['amount']);
        $this->assertSame('MYR', $result['currency']);
        $this->assertSame('2026-09-18', $result['document_date']);
        $this->assertTrue($result['extracted_data']['interpretation']['expense_eligible']);
    }

    public function test_unpaid_invoice_is_not_expense_eligible(): void
    {
        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Invoice',
            'merchant' => 'Acme',
            'amounts' => [[
                'value' => 600,
                'currency' => 'MYR',
                'role' => 'total_due',
                'payment_status' => 'unpaid',
                'uncertain' => false,
            ]],
            'dates' => [
                ['value' => '2026-09-01', 'role' => 'document', 'uncertain' => false],
                ['value' => '2026-09-30', 'role' => 'due', 'uncertain' => false],
            ],
        ], []);

        $this->assertSame('600', $result['amount']);
        $this->assertSame('2026-09-01', $result['document_date']);
        $this->assertFalse($result['extracted_data']['interpretation']['expense_eligible']);
    }

    public function test_receipt_with_only_total_due_is_coerced_to_invoice(): void
    {
        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Receipt',
            'merchant' => 'boong',
            'amounts' => [[
                'value' => 174.35,
                'currency' => 'MYR',
                'role' => 'total_due',
                'payment_status' => 'unpaid',
                'uncertain' => false,
            ]],
            'dates' => [],
        ], []);

        $this->assertSame('Invoice', $result['document_type']);
        $this->assertSame('174.35', $result['amount']);
        $this->assertSame('MYR', $result['currency']);
        $this->assertSame('total_due', $result['extracted_data']['interpretation']['primary_amount_role']);
        $this->assertFalse($result['extracted_data']['interpretation']['expense_eligible']);
    }

    public function test_insurance_coverage_is_not_primary_or_expense_eligible(): void
    {
        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Insurance Policy',
            'merchant' => 'Etiqa',
            'amounts' => [
                [
                    'value' => 1200,
                    'currency' => 'MYR',
                    'role' => 'premium',
                    'payment_status' => 'unknown',
                    'uncertain' => false,
                ],
                [
                    'value' => 100000,
                    'currency' => 'MYR',
                    'role' => 'coverage',
                    'payment_status' => 'unknown',
                    'uncertain' => false,
                ],
            ],
            'dates' => [
                ['value' => '2026-01-01', 'role' => 'document', 'uncertain' => false],
                ['value' => '2027-01-01', 'role' => 'expiry', 'uncertain' => false],
            ],
        ], []);

        $this->assertSame('1200', $result['amount']);
        $this->assertNotSame('100000', $result['amount']);
        $this->assertFalse($result['extracted_data']['interpretation']['expense_eligible']);
        $this->assertCount(2, $result['extracted_data']['dates']);
    }

    public function test_bank_statement_balance_is_not_primary_amount(): void
    {
        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Bank Statement',
            'merchant' => 'Maybank',
            'amounts' => [[
                'value' => 5000,
                'currency' => 'MYR',
                'role' => 'balance',
                'payment_status' => 'unknown',
                'uncertain' => false,
            ]],
            'dates' => [[
                'value' => '2026-08-31',
                'role' => 'period_end',
                'uncertain' => false,
            ]],
        ], []);

        $this->assertNull($result['amount']);
        $this->assertNull($result['document_date']);
        $this->assertFalse($result['extracted_data']['interpretation']['expense_eligible']);
    }

    public function test_warranty_keeps_expiry_without_forcing_amount(): void
    {
        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Warranty',
            'merchant' => 'BrandCo',
            'product_name' => 'Mixer',
            'amounts' => [],
            'dates' => [[
                'value' => '2028-01-01',
                'role' => 'expiry',
                'uncertain' => false,
            ]],
        ], []);

        $this->assertNull($result['amount']);
        $this->assertNull($result['document_date']);
        $this->assertSame('Mixer', $result['extracted_data']['product_name']);
    }

    public function test_certificate_without_money_preserves_nulls(): void
    {
        $this->travelTo('2026-11-02 10:00:00');

        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Academic Certificate',
            'merchant' => 'UMS',
            'subject' => "Bachelor's Degree",
            'amounts' => [],
            'dates' => [[
                'value' => '2024-10-01',
                'role' => 'document',
                'uncertain' => false,
            ]],
        ], []);

        $this->assertNull($result['amount']);
        $this->assertSame('2024-10-01', $result['document_date']);
        $this->assertFalse($result['extracted_data']['interpretation']['expense_eligible']);
    }

    public function test_receipt_year_far_from_today_is_rejected_as_uncertain(): void
    {
        $this->travelTo('2026-11-02 10:00:00');

        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Receipt',
            'merchant' => 'Jollibee (Centre Point)',
            'reference_number' => 'p2kp-2644-6g9b',
            'amounts' => [[
                'value' => 34.09,
                'currency' => 'MYR',
                'role' => 'paid',
                'payment_status' => 'paid',
                'uncertain' => false,
            ]],
            'dates' => [[
                'value' => '2024-10-26',
                'role' => 'transaction',
                'uncertain' => false,
            ]],
        ], []);

        $this->assertNull($result['document_date']);
        $this->assertTrue($result['extracted_data']['dates'][0]['uncertain']);
        $this->assertSame('34.09', $result['amount']);
    }

    public function test_receipt_year_within_one_of_today_is_kept(): void
    {
        $this->travelTo('2026-11-02 10:00:00');

        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Receipt',
            'merchant' => 'Store',
            'amounts' => [[
                'value' => 28.18,
                'currency' => 'MYR',
                'role' => 'paid',
                'payment_status' => 'paid',
                'uncertain' => false,
            ]],
            'dates' => [[
                'value' => '2025-10-26',
                'role' => 'transaction',
                'uncertain' => false,
            ]],
        ], []);

        $this->assertSame('2025-10-26', $result['document_date']);
    }

    public function test_receipt_with_completed_current_year_is_kept(): void
    {
        $this->travelTo('2026-11-02 10:00:00');

        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Receipt',
            'merchant' => 'DubuYo (KK Times Square)',
            'reference_number' => 'q7kw-2644-focn',
            'amounts' => [[
                'value' => 28.18,
                'currency' => 'MYR',
                'role' => 'paid',
                'payment_status' => 'paid',
                'uncertain' => false,
            ]],
            'dates' => [[
                'value' => '2026-10-26',
                'role' => 'transaction',
                'uncertain' => false,
            ]],
        ], []);

        $this->assertSame('2026-10-26', $result['document_date']);
    }

    public function test_missing_information_stays_unknown(): void
    {
        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => null,
            'merchant' => null,
            'amounts' => [],
            'dates' => [],
        ], []);

        $this->assertNull($result['document_type']);
        $this->assertNull($result['amount']);
        $this->assertNull($result['document_date']);
        $this->assertFalse($result['extracted_data']['interpretation']['expense_eligible']);
    }

    public function test_due_date_is_not_used_as_browse_document_date(): void
    {
        $interpreted = app(DocumentInterpreter::class)->interpret('Invoice', [
            'amounts' => [],
            'dates' => [
                ['value' => '2026-09-30', 'role' => 'due', 'uncertain' => false],
            ],
        ]);

        $this->assertNull($interpreted['document_date']);
    }

    public function test_invalid_roles_are_dropped(): void
    {
        $result = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Receipt',
            'amounts' => [[
                'value' => 10,
                'currency' => 'MYR',
                'role' => 'not_a_role',
                'payment_status' => 'paid',
            ]],
            'dates' => [[
                'value' => '2026-01-01',
                'role' => 'birthday',
            ]],
        ], []);

        $this->assertSame([], $result['extracted_data']['amounts']);
        $this->assertSame([], $result['extracted_data']['dates']);
    }

    public function test_single_element_array_response_is_unwrapped(): void
    {
        $analyzer = app(GeminiDocumentAnalyzer::class);

        $payload = $analyzer->unwrapExtractionPayload([
            [
                'document_type' => 'Insurance Policy',
                'merchant' => 'Etiqa General Takaful Berhad',
                'recommended_category_id' => 13,
                'filing_context' => 'vehicle',
                'amounts' => [[
                    'value' => 404.19,
                    'currency' => 'MYR',
                    'role' => 'premium',
                    'payment_status' => 'unpaid',
                    'uncertain' => false,
                ]],
                'dates' => [[
                    'value' => '2026-06-18',
                    'role' => 'document',
                    'uncertain' => false,
                ]],
            ],
        ]);

        $this->assertSame('Insurance Policy', $payload['document_type']);

        $result = $analyzer->sanitizeExtraction([
            [
                'document_type' => 'Insurance Policy',
                'merchant' => 'Etiqa General Takaful Berhad',
                'recommended_category_id' => 13,
                'filing_context' => 'vehicle',
                'amounts' => [[
                    'value' => 404.19,
                    'currency' => 'MYR',
                    'role' => 'premium',
                    'payment_status' => 'unpaid',
                    'uncertain' => false,
                ]],
                'dates' => [[
                    'value' => '2026-06-18',
                    'role' => 'document',
                    'uncertain' => false,
                ]],
            ],
        ], [13]);

        $this->assertSame('Insurance Policy', $result['document_type']);
        $this->assertSame('Etiqa General Takaful Berhad', $result['merchant']);
        $this->assertSame(13, $result['recommended_category_id']);
        $this->assertSame('vehicle', $result['filing_context']);
        $this->assertSame('404.19', $result['amount']);
        $this->assertSame('2026-06-18', $result['document_date']);
    }
}
