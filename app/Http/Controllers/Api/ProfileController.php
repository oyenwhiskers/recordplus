<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Services\ProfileOnboardingService;
use App\Support\CurrencyOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profiles = $request->user()->profiles()->with('categories')->orderBy('name')->get();

        return response()->json(['profiles' => $profiles]);
    }

    public function store(Request $request, ProfileOnboardingService $onboarding): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'business' => ['sometimes', 'boolean'],
        ]);

        $profile = $onboarding->createProfile(
            $request->user(),
            $data['name'],
            (bool) ($data['business'] ?? false)
        );

        return response()->json(['profile' => $profile], 201);
    }

    public function update(Request $request, Profile $profile): JsonResponse
    {
        $this->authorize('update', $profile);

        if ($request->exists('base_currency')) {
            $request->merge([
                'base_currency' => strtoupper(trim((string) $request->input('base_currency'))),
            ]);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'base_currency' => ['sometimes', 'required', 'string', 'size:3', Rule::in(CurrencyOptions::CODES)],
        ]);

        if ($data === []) {
            return response()->json(['profile' => $profile->load('categories')]);
        }

        $profile->update($data);

        return response()->json(['profile' => $profile->load('categories')]);
    }

    public function activate(Request $request, Profile $profile): JsonResponse
    {
        $this->authorize('view', $profile);

        $request->user()->forceFill(['active_profile_id' => $profile->id])->save();

        return response()->json([
            'user' => $request->user()->fresh(['activeProfile', 'profiles.categories']),
        ]);
    }

    public function destroy(Request $request, Profile $profile): JsonResponse
    {
        $this->authorize('delete', $profile);

        $user = $request->user();

        if ($user->active_profile_id === $profile->id) {
            $fallback = $user->profiles()
                ->whereKeyNot($profile->id)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->first();

            $user->forceFill(['active_profile_id' => $fallback?->id])->save();
        }

        $profile->delete();

        return response()->json([
            'message' => 'Profile deleted',
            'user' => $user->fresh(['activeProfile', 'profiles.categories']),
        ]);
    }
}
