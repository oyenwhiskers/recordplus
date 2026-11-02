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
    public function __construct(
        private DocumentUserLocks $userLocks,
        private DocumentImageOptimizer $imageOptimizer,
    ) {}

    public function upload(User $user, Profile $profile, UploadedFile $file): Document
    {
        abort_unless($profile->user_id === $user->id, 403);

        $stored = $this->storeFile($user->id, $profile->id, $file);

        $document = Document::query()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => DocumentStatus::Processing,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $stored['path'],
            'mime_type' => $stored['mime'],
            'file_size' => $stored['size'],
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

        $stored = $this->storeFile($document->user_id, $document->profile_id, $file);

        $this->userLocks->clearFieldLocks($document);

        $document->forceFill([
            'storage_path' => $stored['path'],
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $stored['mime'],
            'file_size' => $stored['size'],
            'title' => $title,
            'status' => DocumentStatus::Processing,
            'ai_error' => null,
            'extracted_data' => $document->extracted_data,
        ])->save();

        if ($oldPath && $oldPath !== $stored['path']) {
            Storage::disk('local')->delete($oldPath);
        }

        $this->queueOrSkipAnalysis($user, $document);

        return $document->fresh(['category', 'profile']);
    }

    /**
     * @return array{path: string, mime: string, size: int}
     */
    private function storeFile(int $userId, int $profileId, UploadedFile $file): array
    {
        $extension = $file->getClientOriginalExtension();
        $path = sprintf(
            'documents/%d/%d/%s.%s',
            $userId,
            $profileId,
            (string) Str::uuid(),
            $extension !== '' ? $extension : 'bin'
        );

        $contents = file_get_contents($file->getRealPath()) ?: '';
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $optimized = $this->imageOptimizer->optimize($contents, $mime);
        if ($optimized !== null) {
            $contents = $optimized['contents'];
            $mime = $optimized['mime'];
        }

        Storage::disk('local')->put($path, $contents);

        return [
            'path' => $path,
            'mime' => $mime,
            'size' => strlen($contents),
        ];
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
