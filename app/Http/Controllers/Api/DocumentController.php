<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Enums\ReminderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentReminder;
use App\Models\Profile;
use App\Services\CurrencyConversionService;
use App\Services\DocumentAttachmentsZipExporter;
use App\Services\DocumentIdentity;
use App\Services\DocumentReminderSync;
use App\Services\DocumentUploadService;
use App\Services\DocumentUserLocks;
use App\Services\FilingEngine;
use App\Support\CurrencyOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profile = $this->activeProfile($request);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', 'max:50'],
            'category_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'string', Rule::in(['newest', 'oldest', 'name_asc', 'name_desc'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12', 'required_with:year'],
            'undated' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Document::query()
            ->with('category')
            ->where('profile_id', $profile->id);

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['category_id'])) {
            $query->where('category_id', $data['category_id']);
        }

        if ($request->boolean('undated')) {
            $query->whereNull('document_date');
        } else {
            if (! empty($data['year'])) {
                $query->whereYear('document_date', (int) $data['year']);
            }

            if (! empty($data['month'])) {
                $query->whereMonth('document_date', (int) $data['month']);
            }

            if (! empty($data['date_from'])) {
                $query->whereDate('document_date', '>=', $data['date_from']);
            }

            if (! empty($data['date_to'])) {
                $query->whereDate('document_date', '<=', $data['date_to']);
            }
        }

        if (! empty($data['q'])) {
            $q = '%'.$data['q'].'%';
            $query->where(function ($builder) use ($q) {
                $builder->where('title', 'like', $q)
                    ->orWhere('document_type', 'like', $q)
                    ->orWhere('merchant', 'like', $q)
                    ->orWhere('reference_number', 'like', $q)
                    ->orWhere('original_filename', 'like', $q)
                    ->orWhere('document_date', 'like', $q)
                    ->orWhere('amount', 'like', $q)
                    ->orWhere('currency', 'like', $q);
            });
        }

        $sort = $data['sort'] ?? 'newest';
        match ($sort) {
            'oldest' => $query
                ->orderByRaw('document_date is null')
                ->orderBy('document_date')
                ->orderBy('created_at'),
            'name_asc' => $query->orderByRaw(
                "coalesce(nullif(title, ''), nullif(merchant, ''), original_filename) asc"
            ),
            'name_desc' => $query->orderByRaw(
                "coalesce(nullif(title, ''), nullif(merchant, ''), original_filename) desc"
            ),
            default => $query
                ->orderByRaw('document_date is null')
                ->orderByDesc('document_date')
                ->orderByDesc('created_at'),
        };

        return response()->json([
            'documents' => $query->paginate(50),
        ]);
    }

    public function export(Request $request, DocumentAttachmentsZipExporter $exporter): BinaryFileResponse
    {
        $profile = $this->activeProfile($request);

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:1900', 'max:2100'],
        ]);

        $year = (int) $data['year'];

        $documents = Document::query()
            ->with('category')
            ->where('profile_id', $profile->id)
            ->whereNotNull('document_date')
            ->whereYear('document_date', $year)
            ->orderBy('document_date')
            ->orderBy('id')
            ->get();

        abort_if($documents->isEmpty(), 422, 'No documents to export for this year.');

        $archive = $exporter->build($documents);

        return response()
            ->download($archive['path'], 'attachments-'.$year.'.zip', [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend(true);
    }

    public function dateFacets(Request $request): JsonResponse
    {
        $profile = $this->activeProfile($request);

        $data = $request->validate([
            'category_id' => ['required', 'integer'],
        ]);

        $category = Category::query()->findOrFail($data['category_id']);
        abort_unless($category->profile_id === $profile->id, 403);

        $base = Document::query()
            ->where('profile_id', $profile->id)
            ->where('category_id', $category->id);

        $undated = (clone $base)->whereNull('document_date')->count();

        $driver = $base->getConnection()->getDriverName();
        $dated = (clone $base)->whereNotNull('document_date');

        if ($driver === 'sqlite') {
            $rows = $dated
                ->selectRaw("cast(strftime('%Y', document_date) as integer) as year, cast(strftime('%m', document_date) as integer) as month, count(*) as total")
                ->groupByRaw("strftime('%Y', document_date), strftime('%m', document_date)")
                ->orderByRaw("strftime('%Y', document_date) desc, strftime('%m', document_date) desc")
                ->get();
        } else {
            $rows = $dated
                ->selectRaw('year(document_date) as year, month(document_date) as month, count(*) as total')
                ->groupByRaw('year(document_date), month(document_date)')
                ->orderByRaw('year(document_date) desc, month(document_date) desc')
                ->get();
        }

        $years = [];
        foreach ($rows as $row) {
            $year = (int) $row->year;
            if (! isset($years[$year])) {
                $years[$year] = [
                    'year' => $year,
                    'total' => 0,
                    'months' => [],
                ];
            }
            $month = (int) $row->month;
            $count = (int) $row->total;
            $years[$year]['total'] += $count;
            $years[$year]['months'][] = [
                'month' => $month,
                'total' => $count,
                'display_amount' => null,
                'display_amount_label' => null,
            ];
        }

        $identity = app(DocumentIdentity::class);
        $conversion = app(CurrencyConversionService::class);
        $baseCurrency = CurrencyOptions::normalize($profile->base_currency ?? null);
        $this->attachConvertedMonthAmounts($base, $years, $identity, $conversion, $baseCurrency);

        $undatedPreview = [
            'display_amount' => null,
            'display_amount_label' => null,
        ];
        if ($undated === 1) {
            $undatedDocument = (clone $base)->whereNull('document_date')->first();
            if ($undatedDocument !== null) {
                $undatedPreview = $this->monthCardAmountPayload($undatedDocument, $identity);
            }
        }

        return response()->json([
            'years' => array_values($years),
            'undated' => $undated,
            'undated_preview' => $undatedPreview,
        ]);
    }

    /**
     * @param  Builder<Document>  $base
     * @param  array<int, array{year: int, total: int, months: list<array{month: int, total: int, display_amount: ?string, display_amount_label: ?string}>}>  $years
     */
    private function attachConvertedMonthAmounts(
        $base,
        array &$years,
        DocumentIdentity $identity,
        CurrencyConversionService $conversion,
        string $baseCurrency,
    ): void {
        $documents = (clone $base)
            ->whereNotNull('document_date')
            ->whereNotNull('amount')
            ->where('amount', '!=', 0)
            ->get(['id', 'document_date', 'amount', 'currency', 'document_type', 'extracted_data']);

        $byYearMonth = [];
        foreach ($documents as $document) {
            if ($document->document_date === null) {
                continue;
            }
            $key = $document->document_date->format('Y-n');
            $byYearMonth[$key] ??= [];
            $byYearMonth[$key][] = $document;
        }

        foreach ($years as $year => &$yearData) {
            foreach ($yearData['months'] as &$monthData) {
                $key = $year.'-'.$monthData['month'];
                $group = collect($byYearMonth[$key] ?? []);
                if ($group->isEmpty()) {
                    continue;
                }

                $sum = $conversion->sumInBaseCurrency($group, $baseCurrency);
                if ($sum['convertible_count'] === 0) {
                    continue;
                }

                $monthData['display_amount'] = $identity->formatMoneyValue(
                    $sum['total_in_base'],
                    $baseCurrency,
                    compact: true,
                );

                if ($group->count() === 1) {
                    $monthData['display_amount_label'] = $identity->displayAmountLabel($group->first());
                }
            }
            unset($monthData);
        }
        unset($yearData);
    }

    /**
     * @return array{display_amount: ?string, display_amount_label: ?string}
     */
    private function monthCardAmountPayload(Document $document, DocumentIdentity $identity): array
    {
        if ($document->amount === null || (float) $document->amount == 0.0) {
            return [
                'display_amount' => null,
                'display_amount_label' => null,
            ];
        }

        $displayAmount = $identity->displayAmount($document, compact: true);
        if ($displayAmount === null) {
            return [
                'display_amount' => null,
                'display_amount_label' => null,
            ];
        }

        return [
            'display_amount' => $displayAmount,
            'display_amount_label' => $identity->displayAmountLabel($document),
        ];
    }

    public function store(Request $request, DocumentUploadService $uploads): JsonResponse
    {
        $profileId = $request->integer('profile_id') ?: $request->user()->active_profile_id;
        $profile = Profile::query()->findOrFail($profileId);
        abort_unless($profile->user_id === $request->user()->id, 403);

        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,pdf,gif'],
        ]);

        $document = $uploads->upload($request->user(), $profile, $request->file('file'));

        return response()->json(['document' => $document], 201);
    }

    public function storeBulk(Request $request, DocumentUploadService $uploads): JsonResponse
    {
        $profileId = $request->integer('profile_id') ?: $request->user()->active_profile_id;
        $profile = Profile::query()->findOrFail($profileId);
        abort_unless($profile->user_id === $request->user()->id, 403);

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:20480', 'mimes:jpg,jpeg,png,webp,pdf,gif'],
        ]);

        $documents = [];
        foreach ($request->file('files') as $file) {
            $documents[] = $uploads->upload($request->user(), $profile, $file);
        }

        return response()->json(['documents' => $documents], 201);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json([
            'document' => $document->load(['category', 'profile']),
        ]);
    }

    public function update(Request $request, Document $document, FilingEngine $engine, DocumentUserLocks $userLocks, DocumentReminderSync $reminderSync): JsonResponse
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'document_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'document_date' => ['sometimes', 'nullable', 'date'],
            'merchant' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => ['sometimes', 'nullable', 'numeric'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:8'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
        ]);

        $lockable = array_values(array_intersect(array_keys($data), DocumentUserLocks::LOCKABLE_FIELDS));
        if ($lockable !== []) {
            $userLocks->lockFields($document, $lockable);
        }

        if (array_key_exists('category_id', $data) && $data['category_id']) {
            $category = Category::query()->findOrFail($data['category_id']);
            abort_unless($category->profile_id === $document->profile_id, 422);
            $document->fill(collect($data)->except('category_id')->all());
            $document = $engine->confirmFiling($document, $category);
        } else {
            $document->fill($data)->save();
            if (! $document->category_id && $document->status !== DocumentStatus::Processing) {
                $document->forceFill(['status' => DocumentStatus::Uncategorised])->save();
            }
            $reminderSync->sync($document);
        }

        return response()->json(['document' => $document->fresh(['category', 'profile'])]);
    }

    public function confirm(Request $request, Document $document, FilingEngine $engine): JsonResponse
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'category_id' => ['required_without:new_category_name', 'nullable', 'integer', 'exists:categories,id'],
            'new_category_name' => ['required_without:category_id', 'nullable', 'string', 'max:100'],
        ]);

        if (! empty($data['new_category_name'])) {
            $document = $engine->approveSuggestedCategory($document, $data['new_category_name']);
        } else {
            $category = Category::query()->findOrFail($data['category_id']);
            abort_unless($category->profile_id === $document->profile_id, 422);
            $document = $engine->confirmFiling($document, $category);
        }

        // shortcut: filing-rule suggestions paused until core filing UX is stable
        return response()->json([
            'document' => $document->load(['category', 'profile']),
            'suggested_rule' => null,
        ]);
    }

    public function retry(Request $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);

        abort_unless(
            $request->user()->ai_enabled && config('services.gemini.enabled') && config('services.gemini.api_key'),
            422,
            'AI analysis is not available.'
        );

        $document->forceFill([
            'status' => DocumentStatus::Processing,
            'ai_error' => null,
        ])->save();

        AnalyzeDocumentJob::dispatch($document->id);

        return response()->json(['document' => $document->fresh(['category', 'profile'])]);
    }

    public function replace(Request $request, Document $document, DocumentUploadService $uploads): JsonResponse
    {
        $this->authorize('update', $document);

        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,pdf,gif'],
        ]);

        $document = $uploads->replace($request->user(), $document, $request->file('file'));

        return response()->json(['document' => $document]);
    }

    public function download(Request $request, Document $document): BinaryFileResponse|StreamedResponse
    {
        $this->authorize('view', $document);

        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);

        $headers = [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            // Auth-gated; browsers may reuse within the session without a second full download.
            'Cache-Control' => 'private, max-age=86400',
        ];

        // inline=1 for in-app PDF/image preview (hash zoom params work on http URLs, not blob:)
        if ($request->boolean('inline')) {
            $response = Storage::disk('local')->response(
                $document->storage_path,
                $document->original_filename,
                $headers,
                'inline'
            );
        } else {
            $response = Storage::disk('local')->download(
                $document->storage_path,
                $document->original_filename,
                $headers
            );
        }

        $etag = '"'.sha1(implode('|', [
            (string) $document->id,
            (string) $document->file_size,
            $document->updated_at?->getTimestamp() ?? 0,
            (string) $document->storage_path,
        ])).'"';

        $response->setEtag($etag);
        $response->isNotModified($request);

        return $response;
    }

    public function move(Request $request, Document $document, FilingEngine $engine): JsonResponse
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
            'category_id' => ['required_without:new_category_name', 'nullable', 'integer', 'exists:categories,id'],
            'new_category_name' => ['required_without:category_id', 'nullable', 'string', 'max:100'],
        ]);

        $profile = Profile::query()->findOrFail($data['profile_id']);
        abort_unless($profile->user_id === $request->user()->id, 403);

        $document->forceFill(['profile_id' => $profile->id])->save();

        if (! empty($data['new_category_name'])) {
            $document = $engine->approveSuggestedCategory($document, $data['new_category_name']);
        } else {
            $category = Category::query()->findOrFail($data['category_id']);
            abort_unless($category->profile_id === $profile->id, 422, 'Category must belong to the target profile.');
            $document = $engine->confirmFiling($document, $category);
        }

        return response()->json([
            'document' => $document->load(['category', 'profile']),
        ]);
    }

    public function destroy(Request $request, Document $document, DocumentReminderSync $reminderSync): JsonResponse
    {
        $this->authorize('delete', $document);

        $reminderSync->cancelPending($document);
        Storage::disk('local')->delete($document->storage_path);
        $document->delete();

        return response()->json(['message' => 'Document deleted']);
    }

    public function dismissReminder(Request $request, Document $document, DocumentReminder $reminder): JsonResponse
    {
        $this->authorize('update', $document);
        abort_unless($reminder->document_id === $document->id, 404);

        if ($reminder->status === ReminderStatus::Pending) {
            $reminder->forceFill(['status' => ReminderStatus::Dismissed])->save();
        }

        return response()->json(['reminder' => $reminder->fresh()]);
    }

    private function activeProfile(Request $request): Profile
    {
        $profile = $request->user()->activeProfile;
        abort_unless($profile, 422, 'No active profile selected.');
        abort_unless($profile->user_id === $request->user()->id, 403);

        return $profile;
    }
}
