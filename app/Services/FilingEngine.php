<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Category;
use App\Models\Document;
use App\Models\FilingPreference;
use App\Models\FilingRule;
use Illuminate\Support\Str;

class FilingEngine
{
    public const RULE_SUGGESTION_THRESHOLD = 3;

    /** @var list<string> */
    public const GENERIC_TITLES = [
        'invoice',
        'receipt',
        'document',
        'untitled',
        'untitled document',
        'general document',
    ];

    /**
     * Match-only synonym groups. Never merge or rename categories.
     *
     * @var list<list<string>>
     */
    private const CATEGORY_SYNONYM_GROUPS = [
        ['vehicle', 'vehicles', 'car documents', 'motor documents', 'car', 'cars'],
        ['fuel', 'gas', 'petrol', 'diesel', 'gasoline', 'fuel expenses', 'vehicle fuel', 'car fuel'],
        ['employment', 'work records', 'career documents', 'work', 'payroll', 'human resources', 'hr'],
        ['education', 'education records', 'academic', 'school', 'qualifications'],
        ['health', 'healthcare', 'medical', 'medical records'],
        ['household', 'utilities', 'home', 'household expenses'],
        ['purchases', 'purchase', 'expenses', 'expense', 'spending'],
        ['sales', 'sale', 'income', 'revenue'],
        ['insurance', 'protection'],
        ['finance', 'banking', 'bank', 'financial'],
        ['property', 'housing', 'real estate'],
        ['contracts', 'contract', 'agreements', 'agreement', 'legal'],
        ['general documents', 'general', 'misc', 'miscellaneous', 'other'],
    ];

    public function __construct(
        private DocumentUserLocks $userLocks,
        private DocumentReminderSync $reminderSync,
    ) {}

    /**
     * @param  array{
     *     document_type: ?string,
     *     title: ?string,
     *     document_date: ?string,
     *     merchant: ?string,
     *     reference_number: ?string,
     *     amount: ?string,
     *     currency: ?string,
     *     recommended_category_id?: ?int,
     *     suggested_new_category_name?: ?string,
     *     filing_context?: ?string,
     *     extracted_data: array<string, mixed>
     * }  $extraction
     */
    public function applyExtraction(Document $document, array $extraction): Document
    {
        $locks = $this->userLocks->read($document);
        $extractedData = is_array($extraction['extracted_data'] ?? null)
            ? $extraction['extracted_data']
            : [];
        $extractedData = $this->userLocks->mergeIntoExtractedData($extractedData, $locks);

        // User-corrected amount invalidates auto expense eligibility.
        if (in_array('amount', $locks['locked_fields'], true)) {
            $interpretation = is_array($extractedData['interpretation'] ?? null)
                ? $extractedData['interpretation']
                : [];
            $interpretation['expense_eligible'] = false;
            $extractedData['interpretation'] = $interpretation;
        }

        // title is optional custom name only — never set from AI extraction.
        $incoming = [
            'document_type' => $extraction['document_type'],
            'document_date' => $extraction['document_date'],
            'merchant' => $extraction['merchant'],
            'reference_number' => $extraction['reference_number'],
            'amount' => $extraction['amount'],
            'currency' => $extraction['currency'],
        ];

        foreach ($locks['locked_fields'] as $field) {
            if (array_key_exists($field, $incoming)) {
                unset($incoming[$field]);
            }
        }

        // Undated AI extraction: file under the upload day so month browse still works.
        if (
            array_key_exists('document_date', $incoming)
            && ($incoming['document_date'] === null || $incoming['document_date'] === '')
            && $document->created_at !== null
        ) {
            $incoming['document_date'] = $document->created_at->timezone(config('app.timezone'))->format('Y-m-d');
        }

        $document->fill([
            ...$incoming,
            'extracted_data' => $extractedData,
            'ai_error' => null,
        ]);

        if ($locks['locked_filing']) {
            // Reanalysis must not reset user filing; restore a stable status after Processing.
            $document->status = $document->category_id
                ? DocumentStatus::Filed
                : DocumentStatus::NeedsReview;
        } else {
            $match = $this->resolveCategory($document, $extraction);

            if ($match['category'] && $match['auto_file']) {
                $document->fill([
                    'category_id' => $match['category']->id,
                    'suggested_category_name' => $match['category']->name,
                    'suggests_new_category' => false,
                    'status' => DocumentStatus::Filed,
                ]);
            } else {
                $document->fill([
                    'category_id' => null,
                    'suggested_category_name' => $match['suggested_name'],
                    'suggests_new_category' => $match['suggests_new'],
                    'status' => DocumentStatus::NeedsReview,
                ]);
            }
        }

        $document->save();
        $this->reminderSync->sync($document);

        return $document->fresh(['category', 'profile']);
    }

    /**
     * @param  array{
     *     recommended_category_id?: ?int,
     *     suggested_new_category_name?: ?string,
     *     filing_context?: ?string,
     *     extracted_data?: array<string, mixed>
     * }  $extraction
     * @return array{category: ?Category, suggested_name: ?string, suggests_new: bool, auto_file: bool}
     */
    public function resolveCategory(Document $document, array $extraction = []): array
    {
        $merchantKey = $this->normalizeMerchant($document->merchant);
        $type = $document->document_type;

        // shortcut: filing rules paused until core filing UX is stable; restore active-rule auto-file match here

        // 1. Strong preference: document type + specific merchant.
        if ($type && $merchantKey !== null) {
            $strong = FilingPreference::query()
                ->where('profile_id', $document->profile_id)
                ->where('document_type', $type)
                ->where('merchant_normalized', $merchantKey)
                ->orderByDesc('confirmation_count')
                ->with('category')
                ->first();

            if ($strong?->category) {
                return $this->existingMatch($strong->category);
            }
        }

        // 2. AI recommended existing category (validated to this profile).
        $recommendedId = $extraction['recommended_category_id'] ?? null;
        if (is_int($recommendedId)) {
            $recommended = Category::query()
                ->where('profile_id', $document->profile_id)
                ->whereKey($recommendedId)
                ->first();

            if ($recommended) {
                return $this->existingMatch($recommended);
            }
        }

        // 3. AI suggested new name — reuse if equivalent category exists; else propose if reusable.
        $suggestedNew = is_string($extraction['suggested_new_category_name'] ?? null)
            ? trim($extraction['suggested_new_category_name'])
            : '';

        if ($suggestedNew !== '') {
            $existing = $this->findExistingCategory((int) $document->profile_id, $suggestedNew);
            if ($existing) {
                return $this->existingMatch($existing);
            }

            if ($this->isReusableCategoryName($suggestedNew)) {
                return [
                    'category' => null,
                    'suggested_name' => $suggestedNew,
                    'suggests_new' => true,
                    'auto_file' => false,
                ];
            }
        }

        // 4. Weak preference: type-only (no merchant), after contextual AI signals.
        if ($type) {
            $weak = FilingPreference::query()
                ->where('profile_id', $document->profile_id)
                ->where('document_type', $type)
                ->whereNull('merchant_normalized')
                ->orderByDesc('confirmation_count')
                ->with('category')
                ->first();

            if ($weak?->category) {
                return $this->existingMatch($weak->category);
            }
        }

        // 5. Contextual fallback (purpose-aware; document type is secondary).
        $heuristic = $this->contextualCategoryName(
            $this->filingContextFrom($document, $extraction),
            $type,
        );

        if ($heuristic !== null) {
            $existing = $this->findExistingCategory((int) $document->profile_id, $heuristic);
            if ($existing) {
                return $this->existingMatch($existing);
            }

            if ($this->isReusableCategoryName($heuristic)) {
                return [
                    'category' => null,
                    'suggested_name' => $heuristic,
                    'suggests_new' => true,
                    'auto_file' => false,
                ];
            }
        }

        // 6. Uncertain — Needs Review without inventing a folder.
        return [
            'category' => null,
            'suggested_name' => null,
            'suggests_new' => false,
            'auto_file' => false,
        ];
    }

    public function confirmFiling(Document $document, Category $category): Document
    {
        $this->userLocks->lockFiling($document);

        $document->fill([
            'category_id' => $category->id,
            'suggested_category_name' => $category->name,
            'suggests_new_category' => false,
            'status' => DocumentStatus::Filed,
            'ai_error' => null,
            'extracted_data' => $document->extracted_data,
        ])->save();

        if ($document->document_type) {
            $this->recordPreference($document, $category);
        }

        $this->reminderSync->sync($document);

        return $document->fresh(['category', 'profile']);
    }

    public function approveSuggestedCategory(Document $document, string $name): Document
    {
        $name = trim($name);
        $category = $this->findExistingCategory((int) $document->profile_id, $name)
            ?? Category::query()->create([
                'profile_id' => $document->profile_id,
                'name' => $name,
                'sort_order' => Category::nextSortOrder((int) $document->profile_id),
            ]);

        return $this->confirmFiling($document, $category);
    }

    public function recordPreference(Document $document, Category $category): FilingPreference
    {
        $preference = FilingPreference::query()->firstOrNew([
            'profile_id' => $document->profile_id,
            'document_type' => $document->document_type,
            'merchant_normalized' => $this->normalizeMerchant($document->merchant),
        ]);

        $preference->category_id = $category->id;
        $preference->confirmation_count = ($preference->exists ? $preference->confirmation_count : 0) + 1;
        $preference->save();

        return $preference;
    }

    public function suggestRuleFromPreference(FilingPreference $preference): ?FilingRule
    {
        if ($preference->confirmation_count < self::RULE_SUGGESTION_THRESHOLD) {
            return null;
        }

        return FilingRule::query()->firstOrCreate(
            [
                'profile_id' => $preference->profile_id,
                'document_type' => $preference->document_type,
                'merchant_normalized' => $preference->merchant_normalized,
            ],
            [
                'category_id' => $preference->category_id,
                'is_active' => false,
            ]
        );
    }

    public function approveRule(FilingRule $rule): FilingRule
    {
        $rule->forceFill(['is_active' => true])->save();

        return $rule;
    }

    public function normalizeMerchant(?string $merchant): ?string
    {
        if ($merchant === null || trim($merchant) === '') {
            return null;
        }

        return Str::lower(trim($merchant));
    }

    public function findExistingCategory(int $profileId, string $name): ?Category
    {
        $normalized = Str::lower(trim($name));
        if ($normalized === '') {
            return null;
        }

        $exact = Category::query()
            ->where('profile_id', $profileId)
            ->whereRaw('lower(name) = ?', [$normalized])
            ->first();

        if ($exact) {
            return $exact;
        }

        $synonyms = $this->synonymsFor($normalized);
        if ($synonyms === []) {
            return null;
        }

        $categories = Category::query()
            ->where('profile_id', $profileId)
            ->get(['id', 'profile_id', 'name']);

        foreach ($categories as $category) {
            if (in_array(Str::lower($category->name), $synonyms, true)) {
                return $category;
            }
        }

        return null;
    }

    public function isReusableCategoryName(string $name): bool
    {
        $trimmed = trim($name);
        if ($trimmed === '' || mb_strlen($trimmed) > 40) {
            return false;
        }

        if (preg_match('/\b(19|20)\d{2}\b/', $trimmed) === 1) {
            return false;
        }

        if (preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec|oktober|okt)\b/i', $trimmed) === 1) {
            return false;
        }

        if (preg_match('/\b(rm|myr|usd|sgd)\s*\d/i', $trimmed) === 1 || preg_match('/\d+\.\d{2}/', $trimmed) === 1) {
            return false;
        }

        if (preg_match('/\b(invoice|receipt|policy)\s*#?\s*\d+/i', $trimmed) === 1) {
            return false;
        }

        if (preg_match('/\d{3,}/', $trimmed) === 1) {
            return false;
        }

        $words = preg_split('/\s+/', $trimmed) ?: [];

        return count($words) <= 4;
    }

    public function isGenericTitle(?string $title, ?string $documentType = null): bool
    {
        if ($title === null || trim($title) === '') {
            return true;
        }

        $normalized = Str::lower(trim($title));

        if (in_array($normalized, self::GENERIC_TITLES, true)) {
            return true;
        }

        if ($documentType !== null && trim($documentType) !== '' && strcasecmp(trim($title), trim($documentType)) === 0) {
            return true;
        }

        return false;
    }

    /**
     * @param  array{
     *     document_type: ?string,
     *     title: ?string,
     *     document_date: ?string,
     *     merchant: ?string,
     *     reference_number: ?string
     * }  $extraction
     */
    public function titleFromExtraction(array $extraction): string
    {
        return $this->resolveTitle($extraction);
    }

    /**
     * Rebuild a generic title from stored extraction/metadata. Returns new title or null if unchanged.
     */
    public function backfillTitle(Document $document): ?string
    {
        if (! $this->isGenericTitle($document->title, $document->document_type)) {
            return null;
        }

        $extracted = is_array($document->extracted_data) ? $document->extracted_data : [];
        $date = $extracted['document_date'] ?? null;
        if ($date === null && $document->document_date) {
            $date = $document->document_date->format('Y-m-d');
        }

        $extraction = [
            'document_type' => $extracted['document_type'] ?? $document->document_type,
            'title' => $extracted['title'] ?? $document->title,
            'document_date' => is_string($date) ? $date : null,
            'merchant' => $extracted['merchant'] ?? $document->merchant,
            'reference_number' => $extracted['reference_number'] ?? $document->reference_number,
        ];

        $newTitle = $this->resolveTitle($extraction);

        if ($newTitle === '' || strcasecmp($newTitle, (string) $document->title) === 0) {
            return null;
        }

        $document->forceFill(['title' => $newTitle])->save();

        return $newTitle;
    }

    /**
     * @return array{category: Category, suggested_name: string, suggests_new: false, auto_file: false}
     */
    private function existingMatch(Category $category): array
    {
        return [
            'category' => $category,
            'suggested_name' => $category->name,
            'suggests_new' => false,
            'auto_file' => false,
        ];
    }

    /**
     * @param  array{
     *     filing_context?: ?string,
     *     extracted_data?: array<string, mixed>
     * }  $extraction
     */
    private function filingContextFrom(Document $document, array $extraction): ?string
    {
        $top = $extraction['filing_context'] ?? null;
        if (is_string($top) && in_array($top, GeminiDocumentAnalyzer::FILING_CONTEXTS, true)) {
            return $top;
        }

        $fromExtraction = $extraction['extracted_data'] ?? null;
        if (is_array($fromExtraction) && is_string($fromExtraction['filing_context'] ?? null)) {
            $context = $fromExtraction['filing_context'];
            if (in_array($context, GeminiDocumentAnalyzer::FILING_CONTEXTS, true)) {
                return $context;
            }
        }

        $stored = is_array($document->extracted_data) ? $document->extracted_data : [];
        $fromDocument = $stored['filing_context'] ?? null;
        if (is_string($fromDocument) && in_array($fromDocument, GeminiDocumentAnalyzer::FILING_CONTEXTS, true)) {
            return $fromDocument;
        }

        return null;
    }

    private function contextualCategoryName(?string $filingContext, ?string $documentType): ?string
    {
        if ($filingContext !== null) {
            return match ($filingContext) {
                'vehicle' => 'Vehicles',
                'fuel' => 'Fuel',
                'employment_income' => 'Employment',
                'supplier_purchase' => 'Purchases',
                'customer_sale' => 'Sales',
                'household_utility' => 'Household',
                'education' => 'Education',
                'healthcare' => 'Health',
                'insurance' => 'Insurance',
                'finance_banking' => 'Finance',
                'property' => 'Property',
                'agreements' => 'Contracts',
                'general' => 'General Documents',
                default => null,
            };
        }

        // Secondary signal only — never map all financial types to Expenses.
        return match ($documentType) {
            'Payslip' => 'Employment',
            'Academic Certificate' => 'Education',
            'Insurance Policy' => 'Insurance',
            'Bank Statement' => 'Finance',
            'Contract' => 'Contracts',
            'Warranty' => 'Purchases',
            'Receipt', 'Invoice' => 'Purchases',
            'General Document' => 'General Documents',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function synonymsFor(string $normalizedName): array
    {
        foreach (self::CATEGORY_SYNONYM_GROUPS as $group) {
            if (in_array($normalizedName, $group, true)) {
                return $group;
            }
        }

        return [];
    }

    /**
     * @param  array{
     *     document_type: ?string,
     *     title: ?string,
     *     document_date: ?string,
     *     merchant: ?string,
     *     reference_number: ?string
     * }  $extraction
     */
    private function resolveTitle(array $extraction): string
    {
        $title = is_string($extraction['title'] ?? null) ? trim($extraction['title']) : '';
        $type = is_string($extraction['document_type'] ?? null) ? trim($extraction['document_type']) : '';

        if ($title !== '' && ! $this->isGenericTitle($title, $type !== '' ? $type : null)) {
            return $title;
        }

        return $this->fallbackTitle($extraction);
    }

    /**
     * @param  array{
     *     document_type: ?string,
     *     document_date: ?string,
     *     merchant: ?string,
     *     reference_number: ?string
     * }  $extraction
     */
    private function fallbackTitle(array $extraction): string
    {
        $merchant = is_string($extraction['merchant'] ?? null) ? trim($extraction['merchant']) : '';
        $type = is_string($extraction['document_type'] ?? null) ? trim($extraction['document_type']) : '';
        $reference = is_string($extraction['reference_number'] ?? null) ? trim($extraction['reference_number']) : '';
        $date = is_string($extraction['document_date'] ?? null) ? trim($extraction['document_date']) : '';

        $parts = array_values(array_filter([
            $merchant !== '' ? $merchant : null,
            $type !== '' ? $type : null,
            $reference !== '' ? $reference : ($date !== '' ? $date : null),
        ]));

        return $parts !== [] ? implode(' — ', $parts) : 'Untitled document';
    }
}
