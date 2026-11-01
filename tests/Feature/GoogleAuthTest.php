<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_callback_creates_new_user_and_onboards(): void
    {
        $this->fakeGoogleUser([
            'id' => 'google-new-1',
            'name' => 'New Google User',
            'email' => 'newgoogle@example.com',
        ]);

        $this->get('/auth/google/callback')
            ->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/');

        $this->assertAuthenticated();

        $user = User::query()->where('email', 'newgoogle@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('google-new-1', $user->google_id);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->active_profile_id);
        $this->assertSame(1, $user->profiles()->count());
    }

    public function test_google_callback_links_existing_email_account(): void
    {
        $existing = User::factory()->create([
            'email' => 'linked@example.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser([
            'id' => 'google-link-1',
            'name' => 'Linked User',
            'email' => 'linked@example.com',
        ]);

        $this->get('/auth/google/callback')
            ->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/');

        $this->assertAuthenticatedAs($existing->fresh());
        $this->assertSame('google-link-1', $existing->fresh()->google_id);
        $this->assertSame(1, User::query()->count());
    }

    public function test_google_callback_logs_in_existing_google_id(): void
    {
        $existing = User::factory()->create([
            'email' => 'known@example.com',
            'google_id' => 'google-known-1',
        ]);

        $this->fakeGoogleUser([
            'id' => 'google-known-1',
            'name' => 'Known User',
            'email' => 'known@example.com',
        ]);

        $this->get('/auth/google/callback')
            ->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/');

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::query()->count());
    }

    public function test_google_callback_failure_redirects_to_login(): void
    {
        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturnSelf();
        Socialite::shouldReceive('user')
            ->once()
            ->andThrow(new \RuntimeException('oauth failed'));

        $this->get('/auth/google/callback')
            ->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/login?error=google');

        $this->assertGuest();
    }

    public function test_google_only_user_can_set_first_password_without_current(): void
    {
        $user = User::factory()->create([
            'password' => null,
            'google_id' => 'google-pass-1',
        ]);

        $this->actingAs($user)
            ->patchJson('/api/me', [
                'name' => $user->name,
                'email' => $user->email,
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertOk();

        $this->assertNotNull($user->fresh()->password);
    }

    /**
     * @param  array{id: string, name: string, email: string}  $attributes
     */
    private function fakeGoogleUser(array $attributes): void
    {
        $googleUser = Mockery::mock(SocialiteUserContract::class);
        $googleUser->shouldReceive('getId')->andReturn($attributes['id']);
        $googleUser->shouldReceive('getName')->andReturn($attributes['name']);
        $googleUser->shouldReceive('getEmail')->andReturn($attributes['email']);

        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturnSelf();
        Socialite::shouldReceive('user')
            ->once()
            ->andReturn($googleUser);
    }
}
