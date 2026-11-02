<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentDownloadCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_inline_download_sends_private_cache_and_etag(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $path = 'documents/test/photo.jpg';
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'storage_path' => $path,
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 15,
        ]);

        $response = $this->actingAs($user)
            ->get('/api/documents/'.$document->id.'/download?inline=1');

        $response->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $cached = $this->actingAs($user)
            ->get('/api/documents/'.$document->id.'/download?inline=1', [
                'If-None-Match' => $etag,
            ]);

        $cached->assertStatus(304);
    }
}
