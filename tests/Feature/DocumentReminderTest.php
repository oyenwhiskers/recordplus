<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\ReminderStatus;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentReminder;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\DocumentExpiryReminderNotification;
use App\Services\DocumentReminderSync;
use App\Services\FilingEngine;
use App\Services\GeminiDocumentAnalyzer;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DocumentReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_filing_document_with_expiry_creates_pending_reminder(): void
    {
        Carbon::setTestNow('2026-10-24');

        [$user, $profile, $category, $document] = $this->filedReadyDocument([
            'document_type' => 'General Document',
            'merchant' => 'JPJ',
            'extracted_data' => [
                'dates' => [[
                    'value' => '2027-01-01',
                    'role' => 'expiry',
                    'uncertain' => false,
                ]],
            ],
        ]);

        $this->actingAs($user)->postJson('/api/documents/'.$document->id.'/confirm', [
            'category_id' => $category->id,
        ])->assertOk();

        $reminder = DocumentReminder::query()->where('document_id', $document->id)->first();
        $this->assertNotNull($reminder);
        $this->assertSame(ReminderStatus::Pending, $reminder->status);
        $this->assertSame('expiry', $reminder->date_role);
        $this->assertSame('2027-01-01', $reminder->source_date?->format('Y-m-d'));
        $this->assertSame('2026-12-02', $reminder->remind_on?->format('Y-m-d'));
    }

    public function test_needs_review_document_does_not_create_reminder(): void
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $document = Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
            'document_type' => 'Warranty',
            'extracted_data' => [
                'dates' => [[
                    'value' => '2028-01-01',
                    'role' => 'expiry',
                    'uncertain' => false,
                ]],
            ],
        ]);

        app(DocumentReminderSync::class)->sync($document);

        $this->assertSame(0, DocumentReminder::query()->where('document_id', $document->id)->count());
    }

    public function test_clearing_expiry_cancels_pending_reminder(): void
    {
        Carbon::setTestNow('2026-10-24');

        [$user, $profile, $category, $document] = $this->filedReadyDocument([
            'document_type' => 'Insurance Policy',
            'extracted_data' => [
                'dates' => [[
                    'value' => '2027-06-01',
                    'role' => 'expiry',
                    'uncertain' => false,
                ]],
            ],
        ]);

        app(FilingEngine::class)->confirmFiling($document, $category);
        $this->assertSame(1, DocumentReminder::query()->where('status', ReminderStatus::Pending)->count());

        $extraction = app(GeminiDocumentAnalyzer::class)->sanitizeExtraction([
            'document_type' => 'Insurance Policy',
            'merchant' => 'Insurer',
            'amounts' => [],
            'dates' => [],
        ], []);

        app(FilingEngine::class)->applyExtraction($document->fresh(), $extraction);

        $reminder = DocumentReminder::query()->where('document_id', $document->id)->first();
        $this->assertSame(ReminderStatus::Cancelled, $reminder?->status);
    }

    public function test_deleting_document_cancels_pending_reminders(): void
    {
        Carbon::setTestNow('2026-10-24');

        [$user, $profile, $category, $document] = $this->filedReadyDocument([
            'document_type' => 'Warranty',
            'extracted_data' => [
                'dates' => [[
                    'value' => '2027-12-01',
                    'role' => 'expiry',
                    'uncertain' => false,
                ]],
            ],
        ]);

        app(FilingEngine::class)->confirmFiling($document, $category);
        $this->assertSame(1, DocumentReminder::query()->where('status', ReminderStatus::Pending)->count());

        $this->actingAs($user)->deleteJson('/api/documents/'.$document->id)->assertOk();

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertSame(0, DocumentReminder::query()->count());
    }

    public function test_send_command_notifies_and_marks_sent(): void
    {
        Carbon::setTestNow('2026-10-24');
        Notification::fake();

        [$user, $profile, $category, $document] = $this->filedReadyDocument([
            'document_type' => 'Warranty',
            'extracted_data' => [
                'dates' => [[
                    'value' => '2026-11-01',
                    'role' => 'expiry',
                    'uncertain' => false,
                ]],
            ],
        ]);

        app(FilingEngine::class)->confirmFiling($document, $category);

        $this->artisan('documents:send-expiry-reminders')->assertSuccessful();

        Notification::assertSentTo($user, DocumentExpiryReminderNotification::class);
        $this->assertSame(ReminderStatus::Sent, DocumentReminder::query()->first()?->status);
    }

    public function test_user_can_dismiss_pending_reminder(): void
    {
        Carbon::setTestNow('2026-10-24');

        [$user, $profile, $category, $document] = $this->filedReadyDocument([
            'document_type' => 'General Document',
            'extracted_data' => [
                'dates' => [[
                    'value' => '2027-03-01',
                    'role' => 'renewal',
                    'uncertain' => false,
                ]],
            ],
        ]);

        app(FilingEngine::class)->confirmFiling($document, $category);
        $reminder = DocumentReminder::query()->firstOrFail();

        $this->actingAs($user)
            ->postJson('/api/documents/'.$document->id.'/reminders/'.$reminder->id.'/dismiss')
            ->assertOk()
            ->assertJsonPath('reminder.status', 'dismissed');

        $this->assertSame(ReminderStatus::Dismissed, $reminder->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: Profile, 2: Category, 3: Document}
     */
    private function filedReadyDocument(array $overrides): array
    {
        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $category = Category::query()->firstOrCreate([
            'profile_id' => $profile->id,
            'name' => 'General Documents',
        ]);

        $document = Document::factory()->create(array_merge([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::NeedsReview,
        ], $overrides));

        return [$user, $profile, $category, $document];
    }
}
