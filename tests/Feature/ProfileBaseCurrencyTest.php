<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileBaseCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_profile_defaults_to_myr(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $this->assertSame('MYR', $profile->fresh()->base_currency);

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.active_profile.base_currency', 'MYR');
    }

    public function test_can_update_active_profile_base_currency(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $this->actingAs($user)
            ->patchJson('/api/profiles/'.$profile->id, ['base_currency' => 'sgd'])
            ->assertOk()
            ->assertJsonPath('profile.base_currency', 'SGD');

        $this->assertSame('SGD', $profile->fresh()->base_currency);
    }

    public function test_rejects_unsupported_base_currency(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $this->actingAs($user)
            ->patchJson('/api/profiles/'.$profile->id, ['base_currency' => 'XYZ'])
            ->assertStatus(422);
    }
}
