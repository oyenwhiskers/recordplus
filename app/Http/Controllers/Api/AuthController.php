<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, ProfileOnboardingService $onboarding): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $onboarding->onboard($user);
        $this->syncAdminFromEnv($user);

        Auth::login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'user' => $this->userPayload($user->fresh(['activeProfile', 'profiles.categories'])),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, true)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        /** @var User $user */
        $user = Auth::user();
        $this->syncAdminFromEnv($user);

        return response()->json([
            'user' => $this->userPayload($user->fresh(['activeProfile', 'profiles.categories'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->syncAdminFromEnv($user);

        return response()->json([
            'user' => $this->userPayload($user->fresh(['activeProfile', 'profiles.categories'])),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => [
                Rule::requiredIf(fn () => filled($request->input('password')) && filled($user->password)),
                'nullable',
                'string',
                'current_password',
            ],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        if (filled($data['password'] ?? null)) {
            $user->password = $data['password'];
        }

        $user->save();
        $this->syncAdminFromEnv($user);

        return response()->json([
            'user' => $this->userPayload($user->fresh(['activeProfile', 'profiles.categories'])),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'ai_enabled' => $user->ai_enabled,
            'is_admin' => $user->is_admin,
            'active_profile_id' => $user->active_profile_id,
            'active_profile' => $user->activeProfile,
            'profiles' => $user->profiles,
        ];
    }

    private function syncAdminFromEnv(User $user): void
    {
        $adminEmail = config('app.admin_email') ?: env('ADMIN_EMAIL');

        if (! is_string($adminEmail) || $adminEmail === '') {
            return;
        }

        if (strcasecmp($user->email, $adminEmail) !== 0 || $user->is_admin) {
            return;
        }

        $user->forceFill(['is_admin' => true])->save();
    }
}
