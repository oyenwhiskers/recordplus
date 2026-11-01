<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class DocumentAttachmentsZipExporter
{
    /**
     * @param  Collection<int, Document>  $documents
     * @return array{path: string, entries: int}
     */
    public function build(Collection $documents): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rp-export-');
        $zipPath = $tmp.'.zip';
        rename($tmp, $zipPath);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            abort(500, 'Could not create export archive.');
        }

        $usedNames = [];
        $entries = 0;

        foreach ($documents as $document) {
            if ($document->document_date === null || $document->storage_path === null) {
                continue;
            }

            if (! Storage::disk('local')->exists($document->storage_path)) {
                continue;
            }

            $monthFolder = $document->document_date->format('F');
            $categoryFolder = $this->safeSegment($document->category?->name ?: 'Uncategorised');
            $filename = $this->safeSegment($document->original_filename ?: 'attachment');
            $relativePath = $monthFolder.'/'.$categoryFolder.'/'.$filename;
            $relativePath = $this->uniquePath($usedNames, $relativePath);

            if ($zip->addFile(Storage::disk('local')->path($document->storage_path), $relativePath)) {
                $entries++;
            }
        }

        $zip->close();

        if ($entries === 0) {
            @unlink($zipPath);
            abort(422, 'No attachments available to export.');
        }

        return ['path' => $zipPath, 'entries' => $entries];
    }

    private function safeSegment(string $value): string
    {
        $cleaned = preg_replace('/[\\\\\/\:\*\?\"\<\>\|\x00-\x1F]+/u', '-', $value) ?? 'file';
        $cleaned = trim($cleaned, " .\t");

        return $cleaned !== '' ? $cleaned : 'file';
    }

    /**
     * @param  array<string, true>  $usedNames
     */
    private function uniquePath(array &$usedNames, string $path): string
    {
        if (! isset($usedNames[$path])) {
            $usedNames[$path] = true;

            return $path;
        }

        $directory = Str::beforeLast($path, '/');
        $basename = Str::afterLast($path, '/');
        $stem = pathinfo($basename, PATHINFO_FILENAME);
        $extension = pathinfo($basename, PATHINFO_EXTENSION);
        $n = 1;

        do {
            $candidate = $directory.'/'.$stem.' ('.$n.')'.($extension !== '' ? '.'.$extension : '');
            $n++;
        } while (isset($usedNames[$candidate]));

        $usedNames[$candidate] = true;

        return $candidate;
    }
}
