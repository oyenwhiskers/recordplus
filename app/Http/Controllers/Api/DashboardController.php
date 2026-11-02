<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Document;
use App\Services\CurrencyConversionService;
use App\Services\DocumentIdentity;
use App\Support\CurrencyOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function show(
        Request $request,
        DocumentIdentity $identity,
        CurrencyConversionService $conversion,
    ): JsonResponse {
        $profile = $request->user()->activeProfile;
        abort_unless($profile && $profile->user_id === $request->user()->id, 403);

        $base = Document::query()->where('profile_id', $profile->id);

        $years = $this->availableYears($base);
        $requestedYear = $request->integer('year') ?: null;
        $year = $requestedYear && in_array($requestedYear, $years, true)
            ? $requestedYear
            : ($years[0] ?? (int) Carbon::now()->year);

        if (! in_array($year, $years, true)) {
            $years = [$year, ...$years];
        }

        $yearScoped = (clone $base)
            ->whereNotNull('document_date')
            ->whereYear('document_date', $year);

        $countsByCategory = (clone $yearScoped)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as aggregate')
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id');

        $baseCurrency = CurrencyOptions::normalize($profile->base_currency ?? null);
        try {
            $displayAmountByCategory = $this->displayAmountsByCategory(
                $yearScoped,
                $identity,
                $conversion,
                $baseCurrency,
            );
        } catch (\Throwable) {
            $displayAmountByCategory = [];
        }

        $categories = Category::query()
            ->where('profile_id', $profile->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'documents_count' => (int) ($countsByCategory[$category->id] ?? 0),
                'display_amount' => $displayAmountByCategory[$category->id] ?? null,
            ])
            ->values()
            ->all();

        $documentsInYear = (int) (clone $yearScoped)->count();
        $categoriesWithDocs = collect($categories)->where('documents_count', '>', 0)->count();

        $needsReview = (clone $base)
            ->where('status', DocumentStatus::NeedsReview)
            ->count();

        return response()->json([
            'year' => $year,
            'years' => $years,
            'totals' => [
                'categories' => $categoriesWithDocs,
                'documents' => $documentsInYear,
            ],
            'categories' => $categories,
            'needs_review' => $needsReview,
        ]);
    }

    /**
     * Year totals converted into the profile base currency.
     *
     * @param  Builder<Document>  $yearScoped
     * @return array<int, string>
     */
    private function displayAmountsByCategory(
        $yearScoped,
        DocumentIdentity $identity,
        CurrencyConversionService $conversion,
        string $baseCurrency,
    ): array {
        $documents = (clone $yearScoped)
            ->whereNotNull('category_id')
            ->whereNotNull('amount')
            ->where('amount', '!=', 0)
            ->get(['category_id', 'amount', 'currency', 'document_date']);

        $displayAmounts = [];
        foreach ($documents->groupBy('category_id') as $categoryId => $group) {
            $sum = $conversion->sumInBaseCurrency($group, $baseCurrency);
            if ($sum['convertible_count'] === 0) {
                continue;
            }
            $formatted = $identity->formatMoneyValue($sum['total_in_base'], $baseCurrency, compact: true);
            if ($formatted !== null) {
                $displayAmounts[(int) $categoryId] = $formatted;
            }
        }

        return $displayAmounts;
    }

    /**
     * @param  Builder<Document>  $base
     * @return list<int>
     */
    private function availableYears($base): array
    {
        $driver = $base->getConnection()->getDriverName();
        $dated = (clone $base)->whereNotNull('document_date');

        if ($driver === 'sqlite') {
            $rows = $dated
                ->selectRaw("cast(strftime('%Y', document_date) as integer) as year")
                ->groupByRaw("strftime('%Y', document_date)")
                ->orderByRaw("strftime('%Y', document_date) desc")
                ->pluck('year');
        } else {
            $rows = $dated
                ->selectRaw('year(document_date) as year')
                ->groupByRaw('year(document_date)')
                ->orderByRaw('year(document_date) desc')
                ->pluck('year');
        }

        return $rows
            ->map(fn ($year) => (int) $year)
            ->filter(fn (int $year) => $year > 0)
            ->values()
            ->all();
    }
}
