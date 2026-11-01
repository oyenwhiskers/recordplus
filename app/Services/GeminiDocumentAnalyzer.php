<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\Document;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GeminiDocumentAnalyzer
{
    /** @var list<string> */
    public const DOCUMENT_TYPES = [
        'Receipt',
        'Invoice',
        'Insurance Policy',
        'Bank Statement',
        'Contract',
        'Payslip',
        'Warranty',
        'Academic Certificate',
        'General Document',
    ];

    /** @var list<string> */
    public const FILING_CONTEXTS = [
        'vehicle',
        'employment_income',
        'supplier_purchase',
        'customer_sale',
        'household_utility',
        'education',
        'healthcare',
        'insurance',
        'finance_banking',
        'property',
        'agreements',
        'general',
    ];

    /** @var list<string> */
    private const OPTIONAL_EXTRACT_KEYS = [
        'subject',
        'issuer',
        'recipient',
        'policy_number',
        'insured',
        'institution',
        'product_name',
        'brand',
        'serial_number',
        'statement_period',
        'parties',
        'notes',
    ];

    /**
     * @return array{
     *     document_type: ?string,
     *     title: null,
     *     document_date: ?string,
     *     merchant: ?string,
     *     reference_number: ?string,
     *     amount: ?string,
     *     currency: ?string,
     *     recommended_category_id: ?int,
     *     suggested_new_category_name: ?string,
     *     filing_context: ?string,
     *     extracted_data: array<string, mixed>
     * }
     */
    public function analyze(Document $document): array
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey || ! config('services.gemini.enabled')) {
            throw new RuntimeException('AI analysis is not available.');
        }

        $contents = Storage::disk('local')->get($document->storage_path);

        if ($contents === null) {
            throw new RuntimeException('Document file is missing.');
        }

        $document->loadMissing(['profile.categories']);

        $profileName = $document->profile?->name ?: 'Personal';
        $categories = $document->profile?->categories
            ->map(fn ($category) => ['id' => $category->id, 'name' => $category->name])
            ->values()
            ->all() ?? [];
        $categoryIds = array_map('intval', array_column($categories, 'id'));
        $categoriesJson = json_encode(['categories' => $categories], JSON_UNESCAPED_UNICODE) ?: '{"categories":[]}';

        $mime = $document->mime_type ?: 'application/octet-stream';
        $model = config('services.gemini.model', 'gemini-3.1-flash-lite');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        $typesList = implode(', ', self::DOCUMENT_TYPES);
        $contextsList = implode(', ', self::FILING_CONTEXTS);
        $amountRoles = implode(', ', DocumentInterpreter::AMOUNT_ROLES);
        $paymentStatuses = implode(', ', DocumentInterpreter::PAYMENT_STATUSES);
        $dateRoles = implode(', ', DocumentInterpreter::DATE_ROLES);

        $prompt = <<<PROMPT
You are the document understanding engine for RecordPlus, an intelligent filing assistant.
The user is filing into the profile named "{$profileName}". Never select or change the profile.

Read the uploaded document image or PDF. Return ONLY valid JSON.
Understand what the document is, its purpose/activity, and the meaning of important amounts and dates.
Classify by meaningful purpose and subject — not by document format alone.
Do not invent values. Use null or omit when unknown. Do not assume every document is financial.
Do not treat every amount as an expense. Monetary role and payment_status are different concepts.

Profile guidance (name only; not a rigid taxonomy):
- Names like "Personal" or family names usually organise life areas (Employment, Vehicles, Health, Household, Education).
- Business-sounding profile names may use Sales, Purchases, Payroll, Tax, Operations when that fits existing categories.
Respect the user's existing category list above all.

Required keys (use null when unknown):
document_type, merchant, reference_number, filing_context, recommended_category_id (integer or null),
suggested_new_category_name (string or null), amounts (array), dates (array).

document_type must be one of: {$typesList}, or null if unknown.

filing_context must be one of: {$contextsList}, or null if unknown.
It describes the activity/subject (e.g. vehicle, employment_income, supplier_purchase, customer_sale), not the file format.
Examples: road tax or motor policy -> vehicle; payslip -> employment_income; invoice you issued to a customer -> customer_sale;
invoice/bill from a supplier -> supplier_purchase; SESB electricity bill -> household_utility; degree certificate -> education.

merchant means the most relevant organisation when present (issuer, merchant, bank, insurer, institution, brand owner).
reference_number means invoice/receipt/policy/agreement/reference id when present.
Use issuer/recipient when readable so invoice direction is clear (supplier vs customer).

amounts: zero or more objects:
{ "value": number, "currency": "ISO or null", "role": one of [{$amountRoles}], "payment_status": one of [{$paymentStatuses}] or null, "related_date": "YYYY-MM-DD or null", "uncertain": boolean }
Examples: insurance premium + coverage; invoice total_due; receipt paid; bank balance. Omit amounts that are unclear.

Marketplace / e-commerce order pages (Shopee, TikTok Shop, etc.):
- These are purchase records: identify shop, order id, total, payment state, and what was bought.
- merchant = shop/seller name (not the marketplace brand unless no shop name is shown).
- reference_number = Order Number / Order ID when present.
- recipient = delivery recipient name when shown; product_name or subject = main product / short order summary.
- notes may include payment method (e.g. COD) and order status when useful.
- filing_context is usually supplier_purchase for goods bought for the profile.
- Prefer the labeled Total / Order Total / Total Payment. Never invent a total from unit price × qty when a labeled total exists.
- Payment not completed (COD, unpaid, awaiting payment, in transit without paid evidence) -> document_type Invoice, amount role total_due, payment_status unpaid.
- Payment completed / paid receipt / payment evidence -> document_type Receipt, amount role paid, payment_status paid or evidence_present.
- UI tags like "Refund for missing items" are not refunds unless a refund amount is shown.

dates: zero or more objects:
{ "value": "YYYY-MM-DD", "role": one of [{$dateRoles}], "uncertain": boolean }
Include document/transaction/due/expiry/renewal/period dates only when clearly present.
Do not invent a document date. Partial labels without a full calendar date (e.g. "7 Nov" with no year) are not enough — omit them; the app defaults undated filings to the upload date.
Guaranteed or estimated delivery may use role due only when a full YYYY-MM-DD is clearly readable.

Also include optional type-specific keys only when clearly readable (otherwise omit or null):
subject, issuer, recipient, policy_number, insured, institution, product_name, brand, serial_number, statement_period, parties, notes.

Do NOT return a title or display name. Laravel formats how the document is shown.
Do NOT return legacy flat amount/document_date fields; use amounts[] and dates[] only.

Existing categories for this profile (id and name only):
{$categoriesJson}

Filing rules:
- Prefer reusing an existing category id when it fits the document's purpose.
- recommended_category_id must be one of those category ids when an existing category fits, otherwise null.
- suggested_new_category_name must be null when recommended_category_id is set.
- Only suggest a new short reusable category name when no existing category fits (e.g. Vehicles, Employment, Health).
- Never suggest category names for a single document, merchant, invoice number, amount, month, or year
  (bad: "Shell Receipt October", "Etiqa Policy 2026", "Invoice 001").
- Do not force Payslip -> Expenses, Invoice -> Expenses, or Insurance Policy -> Insurance when another existing category fits better.
PROMPT;

        try {
            $response = Http::timeout(60)
                ->withQueryParameters(['key' => $apiKey])
                ->post($endpoint, [
                    'contents' => [[
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inline_data' => [
                                    'mime_type' => $mime,
                                    'data' => base64_encode($contents),
                                ],
                            ],
                        ],
                    ]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'temperature' => 0.1,
                    ],
                ]);
        } catch (Throwable $e) {
            $this->logUsage($document, $model, [], 'failed');
            throw new RuntimeException('Gemini request failed: '.$e->getMessage(), 0, $e);
        }

        $json = $response->json() ?? [];
        $usage = is_array($json['usageMetadata'] ?? null) ? $json['usageMetadata'] : [];

        if (! $response->successful()) {
            $this->logUsage($document, $model, $usage, 'failed');
            throw new RuntimeException('Gemini request failed: '.$response->body());
        }

        $text = $this->responseText($json);

        if ($text === null) {
            $this->logUsage($document, $model, $usage, 'failed');
            throw new RuntimeException('Gemini returned an empty response.');
        }

        try {
            /** @var array<string, mixed> $parsed */
            $parsed = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->logUsage($document, $model, $usage, 'failed');
            throw new RuntimeException('Gemini returned invalid JSON.', 0, $e);
        }

        $this->logUsage($document, $model, $usage, 'success');

        return $this->sanitizeExtraction($this->unwrapExtractionPayload($parsed), $categoryIds);
    }

    /**
     * Gemini sometimes returns `[{...}]` instead of `{...}`.
     *
     * @param  array<mixed>  $parsed
     * @return array<string, mixed>
     */
    public function unwrapExtractionPayload(array $parsed): array
    {
        if ($parsed === []) {
            return $parsed;
        }

        if (array_is_list($parsed) && count($parsed) === 1 && is_array($parsed[0])) {
            /** @var array<string, mixed> $inner */
            $inner = $parsed[0];

            return $inner;
        }

        // Non-list single wrapper keyed as 0 (some JSON decoders / model quirks).
        if (
            count($parsed) === 1
            && array_key_exists(0, $parsed)
            && is_array($parsed[0])
            && ! array_key_exists('document_type', $parsed)
        ) {
            /** @var array<string, mixed> $inner */
            $inner = $parsed[0];

            return $inner;
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<int>  $categoryIds
     * @return array{
     *     document_type: ?string,
     *     title: null,
     *     document_date: ?string,
     *     merchant: ?string,
     *     reference_number: ?string,
     *     amount: ?string,
     *     currency: ?string,
     *     recommended_category_id: ?int,
     *     suggested_new_category_name: ?string,
     *     filing_context: ?string,
     *     extracted_data: array<string, mixed>
     * }
     */
    public function sanitizeExtraction(array $parsed, array $categoryIds): array
    {
        $parsed = $this->unwrapExtractionPayload($parsed);

        $documentType = $this->nullableString($parsed['document_type'] ?? null);
        if ($documentType !== null && ! in_array($documentType, self::DOCUMENT_TYPES, true)) {
            $documentType = null;
        }

        $recommendedCategoryId = $this->nullableInt($parsed['recommended_category_id'] ?? null);
        if ($recommendedCategoryId !== null && ! in_array($recommendedCategoryId, $categoryIds, true)) {
            $recommendedCategoryId = null;
        }

        $suggestedNew = $this->nullableString($parsed['suggested_new_category_name'] ?? null);
        if ($recommendedCategoryId !== null) {
            $suggestedNew = null;
        }

        $filingContext = $this->nullableString($parsed['filing_context'] ?? null);
        if ($filingContext !== null && ! in_array($filingContext, self::FILING_CONTEXTS, true)) {
            $filingContext = null;
        }

        $amounts = $this->sanitizeAmounts($parsed['amounts'] ?? null);
        $dates = $this->sanitizeDates($parsed['dates'] ?? null);

        // Backward-compatible: accept legacy flat fields when arrays absent.
        if ($amounts === [] && isset($parsed['amount']) && is_numeric($parsed['amount'])) {
            $amounts[] = [
                'value' => (string) $parsed['amount'],
                'currency' => $this->nullableString($parsed['currency'] ?? null),
                'role' => 'other',
                'payment_status' => 'unknown',
                'related_date' => null,
                'uncertain' => true,
            ];
        }

        if ($dates === [] && ($legacyDate = $this->nullableDate($parsed['document_date'] ?? null)) !== null) {
            $dates[] = [
                'value' => $legacyDate,
                'role' => 'document',
                'uncertain' => false,
            ];
        }

        // COD / unpaid order screenshots often arrive as Receipt + total_due; Invoice path stores the total.
        if ($documentType === 'Receipt' && $this->shouldCoerceReceiptTotalDueToInvoice($amounts)) {
            $documentType = 'Invoice';
        }

        $sanitized = [
            'document_type' => $documentType,
            'merchant' => $this->nullableString($parsed['merchant'] ?? null),
            'reference_number' => $this->nullableString($parsed['reference_number'] ?? null),
            'recommended_category_id' => $recommendedCategoryId,
            'suggested_new_category_name' => $suggestedNew,
            'amounts' => $amounts,
            'dates' => $dates,
        ];

        if ($filingContext !== null) {
            $sanitized['filing_context'] = $filingContext;
        }

        foreach (self::OPTIONAL_EXTRACT_KEYS as $key) {
            $value = $this->nullableString($parsed[$key] ?? null);
            if ($value !== null) {
                $sanitized[$key] = $value;
            }
        }

        $interpreted = app(DocumentInterpreter::class)->interpret($documentType, $sanitized);

        return [
            'document_type' => $documentType,
            'title' => null,
            'document_date' => $interpreted['document_date'],
            'merchant' => $sanitized['merchant'],
            'reference_number' => $sanitized['reference_number'],
            'amount' => $interpreted['amount'],
            'currency' => $interpreted['currency'],
            'recommended_category_id' => $recommendedCategoryId,
            'suggested_new_category_name' => $suggestedNew,
            'filing_context' => $filingContext,
            'extracted_data' => $interpreted['extracted_data'],
        ];
    }

    /**
     * @param  list<array{value: string, currency: ?string, role: string, payment_status: ?string, related_date: ?string, uncertain: bool}>  $amounts
     */
    private function shouldCoerceReceiptTotalDueToInvoice(array $amounts): bool
    {
        $clearPaid = array_values(array_filter(
            $amounts,
            fn (array $amount): bool => ($amount['role'] ?? null) === 'paid' && empty($amount['uncertain']),
        ));

        if ($clearPaid !== []) {
            return false;
        }

        $clearTotalDue = array_values(array_filter(
            $amounts,
            fn (array $amount): bool => ($amount['role'] ?? null) === 'total_due' && empty($amount['uncertain']),
        ));

        return count($clearTotalDue) === 1;
    }

    /**
     * @return list<array{value: string, currency: ?string, role: string, payment_status: ?string, related_date: ?string, uncertain: bool}>
     */
    private function sanitizeAmounts(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row) || ! isset($row['value']) || ! is_numeric($row['value'])) {
                continue;
            }

            $role = $this->nullableString($row['role'] ?? null);
            if ($role === null || ! in_array($role, DocumentInterpreter::AMOUNT_ROLES, true)) {
                continue;
            }

            $status = $this->nullableString($row['payment_status'] ?? null);
            if ($status !== null && ! in_array($status, DocumentInterpreter::PAYMENT_STATUSES, true)) {
                $status = 'unknown';
            }

            $out[] = [
                'value' => (string) $row['value'],
                'currency' => $this->nullableString($row['currency'] ?? null),
                'role' => $role,
                'payment_status' => $status,
                'related_date' => $this->nullableDate($row['related_date'] ?? null),
                'uncertain' => (bool) ($row['uncertain'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{value: string, role: string, uncertain: bool}>
     */
    private function sanitizeDates(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $value = $this->nullableDate($row['value'] ?? null);
            $role = $this->nullableString($row['role'] ?? null);
            if ($value === null || $role === null || ! in_array($role, DocumentInterpreter::DATE_ROLES, true)) {
                continue;
            }

            $out[] = [
                'value' => $value,
                'role' => $role,
                'uncertain' => (bool) ($row['uncertain'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private function logUsage(Document $document, string $model, array $usage, string $status): void
    {
        AiUsageLog::query()->create([
            'user_id' => $document->user_id,
            'document_id' => $document->id,
            'model' => $model,
            'prompt_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
            'completion_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
            'total_tokens' => (int) ($usage['totalTokenCount'] ?? (
                (int) ($usage['promptTokenCount'] ?? 0) + (int) ($usage['candidatesTokenCount'] ?? 0)
            )),
            'status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function responseText(array $json): ?string
    {
        $parts = data_get($json, 'candidates.0.content.parts', []);

        if (! is_array($parts)) {
            return null;
        }

        foreach ($parts as $part) {
            if (! is_array($part) || ($part['thought'] ?? false) === true) {
                continue;
            }

            $text = $part['text'] ?? null;

            if (is_string($text) && trim($text) !== '') {
                return $text;
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit(trim($value))) {
            return (int) trim($value);
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    private function nullableDate(mixed $value): ?string
    {
        $date = $this->nullableString($value);

        if ($date === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year) ? $date : null;
    }
}
