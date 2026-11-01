<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentDownloadInlineTest extends TestCase
{
    use RefreshDatabase;

    public function test_inline_download_serves_content_disposition_inline(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $path = 'documents/test/invoice.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'storage_path' => $path,
            'original_filename' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $response = $this->actingAs($user)
            ->get('/api/documents/'.$document->id.'/download?inline=1');

        $response->assertOk();
        $this->assertStringContainsString(
            'inline',
            strtolower((string) $response->headers->get('content-disposition'))
        );
    }
}
