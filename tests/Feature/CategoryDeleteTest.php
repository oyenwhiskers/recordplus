<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Document;
use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_delete_category_and_documents_remain(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $category = Category::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Custom Folder',
        ]);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $category->id,
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/categories/'.$category->id)
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertNull($document->fresh()->category_id);
    }
}
