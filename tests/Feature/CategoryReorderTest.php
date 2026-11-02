<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Profile;
use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryReorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_are_listed_by_sort_order(): void
    {
        [$user, $profile] = $this->onboardUser();

        $alpha = Category::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Alpha',
            'sort_order' => 2,
        ]);
        $beta = Category::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Beta',
            'sort_order' => 0,
        ]);
        $gamma = Category::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Gamma',
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('categories.0.id', $beta->id)
            ->assertJsonPath('categories.1.id', $gamma->id)
            ->assertJsonPath('categories.2.id', $alpha->id);
    }

    public function test_user_can_reorder_categories(): void
    {
        [$user, $profile] = $this->onboardUser();

        $first = Category::query()->create([
            'profile_id' => $profile->id,
            'name' => 'First',
            'sort_order' => 0,
        ]);
        $second = Category::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Second',
            'sort_order' => 1,
        ]);
        $third = Category::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Third',
            'sort_order' => 2,
        ]);

        $this->actingAs($user)
            ->patchJson('/api/categories/reorder', [
                'ids' => [$third->id, $first->id, $second->id],
            ])
            ->assertOk()
            ->assertJsonPath('categories.0.id', $third->id)
            ->assertJsonPath('categories.1.id', $first->id)
            ->assertJsonPath('categories.2.id', $second->id);

        $this->assertSame(0, $third->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);
        $this->assertSame(2, $second->fresh()->sort_order);

        $this->actingAs($user)
            ->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('categories.0.id', $third->id)
            ->assertJsonPath('categories.1.id', $first->id)
            ->assertJsonPath('categories.2.id', $second->id);
    }

    public function test_reorder_rejects_foreign_category_ids(): void
    {
        [$user, $profile] = $this->onboardUser();
        $otherUser = User::factory()->create();
        $otherProfile = app(ProfileOnboardingService::class)->onboard($otherUser);

        $own = Category::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Own',
            'sort_order' => 0,
        ]);
        $foreign = Category::query()->create([
            'profile_id' => $otherProfile->id,
            'name' => 'Foreign',
            'sort_order' => 0,
        ]);

        $this->actingAs($user)
            ->patchJson('/api/categories/reorder', [
                'ids' => [$own->id, $foreign->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ids']);
    }

    public function test_reorder_rejects_incomplete_category_list(): void
    {
        [$user, $profile] = $this->onboardUser();

        $first = Category::query()->create([
            'profile_id' => $profile->id,
            'name' => 'First',
            'sort_order' => 0,
        ]);
        Category::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Second',
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->patchJson('/api/categories/reorder', [
                'ids' => [$first->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ids']);
    }

    public function test_new_category_appends_after_existing_sort_order(): void
    {
        [$user, $profile] = $this->onboardUser();

        Category::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Existing',
            'sort_order' => 0,
        ]);

        $this->actingAs($user)
            ->postJson('/api/categories', ['name' => 'Newest'])
            ->assertCreated()
            ->assertJsonPath('category.sort_order', 1);

        $this->actingAs($user)
            ->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('categories.0.name', 'Existing')
            ->assertJsonPath('categories.1.name', 'Newest');
    }

    /**
     * @return array{0: User, 1: Profile}
     */
    private function onboardUser(): array
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        return [$user, $profile];
    }
}
