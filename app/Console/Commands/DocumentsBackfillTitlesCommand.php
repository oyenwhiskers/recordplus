<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\FilingEngine;
use Illuminate\Console\Command;

class DocumentsBackfillTitlesCommand extends Command
{
    protected $signature = 'documents:backfill-titles {--dry-run : Show changes without saving}';

    protected $description = 'Rebuild generic document titles from stored extraction metadata without calling Gemini';

    public function handle(FilingEngine $engine): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        Document::query()
            ->orderBy('id')
            ->chunkById(100, function ($documents) use ($engine, $dryRun, &$updated) {
                foreach ($documents as $document) {
                    if (! $engine->isGenericTitle($document->title, $document->document_type)) {
                        continue;
                    }

                    if ($dryRun) {
                        $extracted = is_array($document->extracted_data) ? $document->extracted_data : [];
                        $date = $extracted['document_date'] ?? ($document->document_date?->format('Y-m-d'));
                        $preview = $engine->titleFromExtraction([
                            'document_type' => $extracted['document_type'] ?? $document->document_type,
                            'title' => $extracted['title'] ?? $document->title,
                            'document_date' => is_string($date) ? $date : null,
                            'merchant' => $extracted['merchant'] ?? $document->merchant,
                            'reference_number' => $extracted['reference_number'] ?? $document->reference_number,
                        ]);

                        if (strcasecmp($preview, (string) $document->title) === 0) {
                            continue;
                        }

                        $this->line("#{$document->id}: {$document->title} → {$preview}");
                        $updated++;

                        continue;
                    }

                    $newTitle = $engine->backfillTitle($document);

                    if ($newTitle === null) {
                        continue;
                    }

                    $this->line("#{$document->id}: → {$newTitle}");
                    $updated++;
                }
            });

        $this->info(($dryRun ? 'Would update' : 'Updated')." {$updated} document(s).");

        return self::SUCCESS;
    }
}
