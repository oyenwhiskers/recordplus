<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Document;
use App\Models\User;
use App\Services\GeminiDocumentAnalyzer;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_admin_users(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        app(ProfileOnboardingService::class)->onboard($user);

        $this->actingAs($user)
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_admin_sees_usage_aggregates(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        app(ProfileOnboardingService::class)->onboard($admin);

        $member = User::factory()->create(['name' => 'Member User', 'email' => 'member@example.com']);
        $profile = app(ProfileOnboardingService::class)->onboard($member);

        Document::factory()->create([
            'user_id' => $member->id,
            'profile_id' => $profile->id,
        ]);

        AiUsageLog::query()->create([
            'user_id' => $member->id,
            'document_id' => null,
            'model' => 'gemini-3.1-flash-lite',
            'prompt_tokens' => 100,
            'completion_tokens' => 40,
            'total_tokens' => 140,
            'status' => 'success',
        ]);

        AiUsageLog::query()->create([
            'user_id' => $member->id,
            'document_id' => null,
            'model' => 'gemini-3.1-flash-lite',
            'prompt_tokens' => 20,
            'completion_tokens' => 0,
            'total_tokens' => 20,
            'status' => 'failed',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonFragment([
                'email' => 'member@example.com',
                'documents_count' => 1,
                'ai_calls_count' => 2,
                'ai_calls_success_count' => 1,
                'ai_calls_failed_count' => 1,
                'total_tokens' => 160,
            ]);
    }

    public function test_admin_can_toggle_ai_enabled(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['ai_enabled' => true]);

        $this->actingAs($admin)
            ->patchJson('/api/admin/users/'.$member->id, ['ai_enabled' => false])
            ->assertOk()
            ->assertJsonPath('user.ai_enabled', false);

        $this->assertFalse($member->fresh()->ai_enabled);
    }

    public function test_admin_user_show_includes_documents_and_log_document(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        app(ProfileOnboardingService::class)->onboard($admin);

        $member = User::factory()->create(['name' => 'Detail User', 'email' => 'detail@example.com']);
        $profile = app(ProfileOnboardingService::class)->onboard($member);

        $document = Document::factory()->create([
            'user_id' => $member->id,
            'profile_id' => $profile->id,
            'title' => 'Shop invoice',
            'original_filename' => 'invoice.pdf',
            'document_type' => 'Invoice',
        ]);

        AiUsageLog::query()->create([
            'user_id' => $member->id,
            'document_id' => $document->id,
            'model' => 'gemini-3.1-flash-lite',
            'prompt_tokens' => 100,
            'completion_tokens' => 40,
            'total_tokens' => 140,
            'status' => 'success',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/users/'.$member->id)
            ->assertOk()
            ->assertJsonPath('user.email', 'detail@example.com')
            ->assertJsonPath('user.documents_count', 1)
            ->assertJsonPath('user.ai_calls_success_count', 1)
            ->assertJsonPath('user.ai_calls_failed_count', 0)
            ->assertJsonPath('recent_documents.0.id', $document->id)
            ->assertJsonPath('recent_documents.0.title', 'Shop invoice')
            ->assertJsonPath('recent_logs.0.document.id', $document->id)
            ->assertJsonPath('recent_logs.0.document.title', 'Shop invoice');
    }

    public function test_gemini_analyzer_logs_usage_metadata(): void
    {
        Storage::fake('local');
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'gemini-3.1-flash-lite',
        ]);

        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        Storage::disk('local')->put('documents/test.jpg', 'fake-image');

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'storage_path' => 'documents/test.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'document_type' => 'Receipt',
                                'title' => 'Shop receipt',
                                'document_date' => '2026-01-01',
                                'merchant' => 'Shop',
                                'reference_number' => null,
                                'amount' => 12.5,
                                'currency' => 'MYR',
                            ]),
                        ]],
                    ],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 220,
                    'candidatesTokenCount' => 55,
                    'totalTokenCount' => 275,
                ],
            ]),
        ]);

        app(GeminiDocumentAnalyzer::class)->analyze($document);

        $this->assertDatabaseHas('ai_usage_logs', [
            'user_id' => $user->id,
            'document_id' => $document->id,
            'prompt_tokens' => 220,
            'completion_tokens' => 55,
            'total_tokens' => 275,
            'status' => 'success',
        ]);
    }
}
