<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProfileOnboardingService
{
    public function onboard(User $user): Profile
    {
        return DB::transaction(function () use ($user) {
            $profile = Profile::query()->create([
                'user_id' => $user->id,
                'name' => 'Personal',
                'is_default' => true,
            ]);

            $user->forceFill(['active_profile_id' => $profile->id])->save();

            return $profile;
        });
    }

    public function createProfile(User $user, string $name, bool $business = false): Profile
    {
        unset($business);

        return DB::transaction(function () use ($user, $name) {
            $profile = Profile::query()->create([
                'user_id' => $user->id,
                'name' => $name,
                'is_default' => false,
            ]);

            if (! $user->active_profile_id) {
                $user->forceFill(['active_profile_id' => $profile->id])->save();
            }

            return $profile->load('categories');
        });
    }
}
