<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentUserLocks;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentReplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_replace_swaps_file_clears_field_locks_and_keeps_filing(): void
    {
        Storage::fake('local');
        Bus::fake();
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        $user = User::factory()->create(['ai_enabled' => true]);
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expenses = $this->categoryFor($profile);

        $oldPath = 'documents/'.$user->id.'/'.$profile->id.'/old-receipt.jpg';
        Storage::disk('local')->put($oldPath, 'old-bytes');

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expenses->id,
            'status' => DocumentStatus::Filed,
            'original_filename' => 'old-receipt.jpg',
            'storage_path' => $oldPath,
            'mime_type' => 'image/jpeg',
            'title' => 'old-receipt',
            'amount' => '10.00',
            'merchant' => 'Old Shop',
            'extracted_data' => [
                '_user' => [
                    'locked_fields' => ['amount', 'merchant'],
                    'locked_filing' => true,
                ],
            ],
        ]);

        $response = $this->actingAs($user)->postJson('/api/documents/'.$document->id.'/replace', [
            'file' => UploadedFile::fake()->create('new-invoice.pdf', 100, 'application/pdf'),
        ]);

        $response->assertOk();
        $document->refresh();

        $this->assertSame('new-invoice.pdf', $document->original_filename);
        $this->assertSame('new-invoice', $document->title);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertNotSame($oldPath, $document->storage_path);
        $this->assertTrue(Storage::disk('local')->exists($document->storage_path));
        $this->assertFalse(Storage::disk('local')->exists($oldPath));
        $this->assertSame($expenses->id, $document->category_id);
        $this->assertSame([], $document->extracted_data['_user']['locked_fields'] ?? null);
        $this->assertTrue($document->extracted_data['_user']['locked_filing'] ?? false);
        $this->assertSame(DocumentStatus::Processing, $document->status);

        Bus::assertDispatched(AnalyzeDocumentJob::class, function (AnalyzeDocumentJob $job) use ($document): bool {
            return $job->documentId === $document->id;
        });
    }

    public function test_replace_without_ai_lands_in_needs_review(): void
    {
        Storage::fake('local');
        Bus::fake();
        config(['services.gemini.enabled' => false]);

        $user = User::factory()->create(['ai_enabled' => true]);
        $profile = app(ProfileOnboardingService::class)->onboard($user);

        $oldPath = 'documents/'.$user->id.'/'.$profile->id.'/scan.png';
        Storage::disk('local')->put($oldPath, 'old');

        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'original_filename' => 'scan.png',
            'storage_path' => $oldPath,
            'mime_type' => 'image/png',
            'title' => 'Custom display name',
        ]);

        $this->actingAs($user)->postJson('/api/documents/'.$document->id.'/replace', [
            'file' => UploadedFile::fake()->image('replacement.jpg'),
        ])->assertOk();

        $document->refresh();

        $this->assertSame('Custom display name', $document->title);
        $this->assertSame('replacement.jpg', $document->original_filename);
        $this->assertSame(DocumentStatus::NeedsReview, $document->status);
        Bus::assertNotDispatched(AnalyzeDocumentJob::class);
    }

    public function test_clear_field_locks_keeps_filing_lock(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'extracted_data' => [
                '_user' => [
                    'locked_fields' => ['amount'],
                    'locked_filing' => true,
                ],
            ],
        ]);

        app(DocumentUserLocks::class)->clearFieldLocks($document);
        $locks = app(DocumentUserLocks::class)->read($document);

        $this->assertSame([], $locks['locked_fields']);
        $this->assertTrue($locks['locked_filing']);
    }
}
