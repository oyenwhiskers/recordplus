<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Document;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profile = $this->activeProfile($request);

        $categories = $profile->categories()
            ->withCount('documents')
            ->get();

        return response()->json([
            'categories' => $categories,
            'counts' => [
                'all' => Document::query()->where('profile_id', $profile->id)->count(),
                'needs_review' => Document::query()
                    ->where('profile_id', $profile->id)
                    ->where('status', DocumentStatus::NeedsReview)
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $profile = $this->activeProfile($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $name = trim($data['name']);
        if ($this->categoryNameExists($profile->id, $name)) {
            throw ValidationException::withMessages([
                'name' => ['A category with this name already exists.'],
            ]);
        }

        $category = Category::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'sort_order' => Category::nextSortOrder($profile->id),
        ]);

        return response()->json(['category' => $category->loadCount('documents')], 201);
    }

    public function reorder(Request $request): JsonResponse
    {
        $profile = $this->activeProfile($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $ids = array_values($data['ids']);
        $ownedIds = $profile->categories()->pluck('id')->all();

        if (count($ids) !== count($ownedIds) || array_diff($ids, $ownedIds) !== []) {
            throw ValidationException::withMessages([
                'ids' => ['Provide every category for the active profile, and no others.'],
            ]);
        }

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $index => $id) {
                Category::query()->whereKey($id)->update(['sort_order' => $index]);
            }
        });

        $categories = $profile->categories()
            ->withCount('documents')
            ->get();

        return response()->json(['categories' => $categories]);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorizeProfile($request, $category->profile_id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $name = trim($data['name']);
        if ($this->categoryNameExists($category->profile_id, $name, $category->id)) {
            throw ValidationException::withMessages([
                'name' => ['A category with this name already exists.'],
            ]);
        }

        $category->update(['name' => $name]);

        return response()->json(['category' => $category->loadCount('documents')]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorizeProfile($request, $category->profile_id);

        $data = $request->validate([
            'reassign_to_category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        if (! empty($data['reassign_to_category_id'])) {
            $target = Category::query()->findOrFail($data['reassign_to_category_id']);
            abort_unless($target->profile_id === $category->profile_id, 422, 'Category must belong to the same profile.');
            abort_if($target->id === $category->id, 422, 'Choose a different category.');

            Document::query()
                ->where('category_id', $category->id)
                ->update(['category_id' => $target->id]);
        }

        // Documents keep their files; unassigned docs get category_id null via nullOnDelete.
        $category->delete();

        return response()->json(['message' => 'Category deleted']);
    }

    private function categoryNameExists(int $profileId, string $name, ?int $ignoreId = null): bool
    {
        $query = Category::query()
            ->where('profile_id', $profileId)
            ->whereRaw('lower(name) = ?', [Str::lower(trim($name))]);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }

    private function activeProfile(Request $request): Profile
    {
        $profile = $request->user()->activeProfile;

        abort_unless($profile, 422, 'No active profile selected.');
        abort_unless($profile->user_id === $request->user()->id, 403);

        return $profile;
    }

    private function authorizeProfile(Request $request, int $profileId): void
    {
        abort_unless(
            $request->user()->profiles()->whereKey($profileId)->exists(),
            403
        );
    }
}
