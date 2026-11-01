<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\Document;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentUploadService
{
    public function __construct(private DocumentUserLocks $userLocks) {}

    public function upload(User $user, Profile $profile, UploadedFile $file): Document
    {
        abort_unless($profile->user_id === $user->id, 403);

        $path = $this->storeFile($user->id, $profile->id, $file);

        $document = Document::query()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize() ?: 0,
            'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Untitled document',
        ]);

        $this->queueOrSkipAnalysis($user, $document);

        return $document->fresh(['category', 'profile']);
    }

    public function replace(User $user, Document $document, UploadedFile $file): Document
    {
        abort_unless($document->user_id === $user->id, 403);

        $oldPath = $document->storage_path;
        $oldStem = pathinfo((string) $document->original_filename, PATHINFO_FILENAME);
        $newStem = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Untitled document';
        $title = $document->title;
        if ($title === null || trim((string) $title) === '' || strcasecmp((string) $title, (string) $oldStem) === 0) {
            $title = $newStem;
        }

        $path = $this->storeFile($document->user_id, $document->profile_id, $file);

        $this->userLocks->clearFieldLocks($document);

        $document->forceFill([
            'storage_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize() ?: 0,
            'title' => $title,
            'status' => DocumentStatus::Processing,
            'ai_error' => null,
            'extracted_data' => $document->extracted_data,
        ])->save();

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        $this->queueOrSkipAnalysis($user, $document);

        return $document->fresh(['category', 'profile']);
    }

    private function storeFile(int $userId, int $profileId, UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $path = sprintf(
            'documents/%d/%d/%s.%s',
            $userId,
            $profileId,
            (string) Str::uuid(),
            $extension !== '' ? $extension : 'bin'
        );

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()) ?: '');

        return $path;
    }

    private function queueOrSkipAnalysis(User $user, Document $document): void
    {
        if ($user->ai_enabled && config('services.gemini.enabled') && config('services.gemini.api_key')) {
            AnalyzeDocumentJob::dispatch($document->id);

            return;
        }

        $document->forceFill([
            'status' => DocumentStatus::NeedsReview,
            'ai_error' => $user->ai_enabled ? null : 'AI analysis is disabled for this account.',
        ])->save();
    }
}
