<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $adminEmail = env('ADMIN_EMAIL');

        if (is_string($adminEmail) && $adminEmail !== '') {
            $user = User::query()->where('email', $adminEmail)->first();

            if ($user) {
                $user->forceFill(['is_admin' => true])->save();
            }
        }
    }
}
