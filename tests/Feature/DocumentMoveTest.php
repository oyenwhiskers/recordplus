<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Category;
use App\Models\Document;
use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentMoveTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_move_document_to_another_profile_category(): void
    {
        $user = User::factory()->create();
        $onboarding = app(ProfileOnboardingService::class);
        $source = $onboarding->onboard($user);
        $target = $onboarding->createProfile($user, 'Other profile');
        $sourceCategory = $this->categoryFor($source);
        $targetCategory = $this->categoryFor($target);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $source->id,
            'category_id' => $sourceCategory->id,
            'status' => DocumentStatus::Filed,
        ]);

        $this->actingAs($user)
            ->postJson('/api/documents/'.$document->id.'/move', [
                'profile_id' => $target->id,
                'category_id' => $targetCategory->id,
            ])
            ->assertOk()
            ->assertJsonPath('document.profile_id', $target->id)
            ->assertJsonPath('document.category_id', $targetCategory->id)
            ->assertJsonPath('document.status', DocumentStatus::Filed->value);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'profile_id' => $target->id,
            'category_id' => $targetCategory->id,
        ]);
    }

    public function test_user_cannot_move_document_to_foreign_profile(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $onboarding = app(ProfileOnboardingService::class);
        $source = $onboarding->onboard($user);
        $foreign = $onboarding->onboard($other);
        $foreignCategory = Category::query()->create([
            'profile_id' => $foreign->id,
            'name' => 'Foreign',
        ]);

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $source->id,
        ]);

        $this->actingAs($user)
            ->postJson('/api/documents/'.$document->id.'/move', [
                'profile_id' => $foreign->id,
                'category_id' => $foreignCategory->id,
            ])
            ->assertForbidden();
    }
}
