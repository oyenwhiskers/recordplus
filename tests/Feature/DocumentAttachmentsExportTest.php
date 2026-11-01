<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\ProfileOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DocumentAttachmentsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_export_attachments(): void
    {
        $this->getJson('/api/documents/export?year=2026')->assertUnauthorized();
    }

    public function test_export_requires_year(): void
    {
        $user = User::factory()->create();
        app(ProfileOnboardingService::class)->onboard($user);

        $this->actingAs($user)
            ->getJson('/api/documents/export')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year']);
    }

    public function test_export_builds_month_category_zip_for_year(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $profile = app(ProfileOnboardingService::class)->onboard($user);
        $expense = $this->categoryFor($profile, 'Expense');
        $income = $this->categoryFor($profile, 'Income');

        $aprilPath = 'documents/export/april.pdf';
        $mayPath = 'documents/export/may.jpg';
        Storage::disk('local')->put($aprilPath, 'april-bytes');
        Storage::disk('local')->put($mayPath, 'may-bytes');

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expense->id,
            'document_date' => '2026-04-10',
            'storage_path' => $aprilPath,
            'original_filename' => 'receipt.pdf',
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $income->id,
            'document_date' => '2026-05-02',
            'storage_path' => $mayPath,
            'original_filename' => 'invoice.jpg',
        ]);

        Document::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'category_id' => $expense->id,
            'document_date' => '2025-04-10',
            'storage_path' => $aprilPath,
            'original_filename' => 'other-year.pdf',
        ]);

        $response = $this->actingAs($user)->get('/api/documents/export?year=2026');

        $response->assertOk();
        $response->assertDownload('attachments-2026.zip');

        $zipPath = $response->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));
        $this->assertNotFalse($zip->locateName('April/Expense/receipt.pdf'));
        $this->assertNotFalse($zip->locateName('May/Income/invoice.jpg'));
        $this->assertFalse($zip->locateName('April/Expense/other-year.pdf'));
        $zip->close();
    }

    public function test_export_rejects_empty_year(): void
    {
        $user = User::factory()->create();
        app(ProfileOnboardingService::class)->onboard($user);

        $this->actingAs($user)
            ->getJson('/api/documents/export?year=2026')
            ->assertUnprocessable();
    }
}
