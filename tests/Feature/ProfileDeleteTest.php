<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_delete_non_default_profile_and_switches_active(): void
    {
        $user = User::factory()->create();
        $onboarding = app(ProfileOnboardingService::class);
        $default = $onboarding->onboard($user);
        $extra = $onboarding->createProfile($user->fresh(), 'Business', true);

        $user->forceFill(['active_profile_id' => $extra->id])->save();

        $this->actingAs($user)
            ->deleteJson('/api/profiles/'.$extra->id)
            ->assertOk()
            ->assertJsonPath('user.active_profile_id', $default->id);

        $this->assertDatabaseMissing('profiles', ['id' => $extra->id]);
        $this->assertSame($default->id, $user->fresh()->active_profile_id);
    }

    public function test_user_cannot_delete_default_profile(): void
    {
        $user = User::factory()->create();
        $default = app(ProfileOnboardingService::class)->onboard($user);

        $this->actingAs($user)
            ->deleteJson('/api/profiles/'.$default->id)
            ->assertForbidden();

        $this->assertDatabaseHas('profiles', ['id' => $default->id]);
    }

    public function test_user_cannot_delete_another_users_profile(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $onboarding = app(ProfileOnboardingService::class);
        $onboarding->onboard($owner);
        $extra = $onboarding->createProfile($owner->fresh(), 'Extra');

        $this->actingAs($other)
            ->deleteJson('/api/profiles/'.$extra->id)
            ->assertForbidden();

        $this->assertDatabaseHas('profiles', ['id' => $extra->id]);
    }
}
