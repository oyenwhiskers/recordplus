<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, ProfileOnboardingService $onboarding): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()->to($frontend.'/login?error=google');
        }

        $googleId = (string) $googleUser->getId();
        $email = $googleUser->getEmail();

        if ($googleId === '' || ! is_string($email) || $email === '') {
            return redirect()->to($frontend.'/login?error=google');
        }

        $user = User::query()->where('google_id', $googleId)->first();

        if (! $user) {
            $user = User::query()->where('email', $email)->first();

            if ($user) {
                $user->forceFill(['google_id' => $googleId])->save();
            } else {
                $user = User::query()->create([
                    'name' => $googleUser->getName() ?: strstr($email, '@', true) ?: $email,
                    'email' => $email,
                    'google_id' => $googleId,
                    'password' => null,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                $onboarding->onboard($user);
            }
        }

        $this->syncAdminFromEnv($user);

        Auth::login($user, true);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return redirect()->to($frontend.'/');
    }

    private function syncAdminFromEnv(User $user): void
    {
        $adminEmail = config('app.admin_email');

        if (! is_string($adminEmail) || $adminEmail === '') {
            return;
        }

        if (strcasecmp($user->email, $adminEmail) !== 0 || $user->is_admin) {
            return;
        }

        $user->forceFill(['is_admin' => true])->save();
    }
}
