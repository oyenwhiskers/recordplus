<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_update_account(): void
    {
        $this->patchJson('/api/me', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
        ])->assertUnauthorized();
    }

    public function test_guest_can_logout(): void
    {
        $this->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out');

        $this->assertGuest();
    }

    public function test_can_update_name_and_email(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);

        $this->actingAs($user)
            ->patchJson('/api/me', [
                'name' => 'New Name',
                'email' => 'new@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonPath('user.email', 'new@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);
    }

    public function test_can_update_password_with_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->patchJson('/api/me', [
                'name' => $user->name,
                'email' => $user->email,
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_rejects_password_change_without_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/me', [
                'name' => $user->name,
                'email' => $user->email,
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'mine@example.com']);

        $this->actingAs($user)
            ->patchJson('/api/me', [
                'name' => $user->name,
                'email' => 'taken@example.com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
