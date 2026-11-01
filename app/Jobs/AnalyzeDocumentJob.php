<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\FilingEngine;
use App\Services\GeminiDocumentAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $documentId) {}

    public function handle(GeminiDocumentAnalyzer $analyzer, FilingEngine $engine): void
    {
        $document = Document::query()->find($this->documentId);

        if (! $document) {
            return;
        }

        // Profile frozen at upload — never reassign here.
        $document->forceFill([
            'status' => DocumentStatus::Processing,
            'ai_error' => null,
        ])->save();

        try {
            $extraction = $analyzer->analyze($document);
            $engine->applyExtraction($document, $extraction);
        } catch (Throwable $e) {
            if ($this->attempts() >= $this->tries) {
                $document->forceFill([
                    'status' => DocumentStatus::ProcessingFailed,
                    'ai_error' => 'We couldn\'t analyse this document. You can retry or file it manually.',
                ])->save();
            }

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $document = Document::query()->find($this->documentId);

        if (! $document) {
            return;
        }

        $document->forceFill([
            'status' => DocumentStatus::ProcessingFailed,
            'ai_error' => 'We couldn\'t analyse this document. You can retry or file it manually.',
        ])->save();
    }
}
