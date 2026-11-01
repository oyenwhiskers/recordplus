<?php

namespace App\Services;

use App\Models\Document;

class DocumentUserLocks
{
    /** @var list<string> */
    public const LOCKABLE_FIELDS = [
        'title',
        'document_type',
        'document_date',
        'merchant',
        'reference_number',
        'amount',
        'currency',
    ];

    /**
     * @return array{locked_fields: list<string>, locked_filing: bool}
     */
    public function read(Document $document): array
    {
        $data = is_array($document->extracted_data) ? $document->extracted_data : [];
        $user = is_array($data['_user'] ?? null) ? $data['_user'] : [];

        $fields = [];
        if (is_array($user['locked_fields'] ?? null)) {
            foreach ($user['locked_fields'] as $field) {
                if (is_string($field) && in_array($field, self::LOCKABLE_FIELDS, true)) {
                    $fields[] = $field;
                }
            }
        }

        return [
            'locked_fields' => array_values(array_unique($fields)),
            'locked_filing' => (bool) ($user['locked_filing'] ?? false),
        ];
    }

    /**
     * @param  list<string>  $fields
     */
    public function lockFields(Document $document, array $fields): void
    {
        $locks = $this->read($document);
        foreach ($fields as $field) {
            if (in_array($field, self::LOCKABLE_FIELDS, true)) {
                $locks['locked_fields'][] = $field;
            }
        }
        $locks['locked_fields'] = array_values(array_unique($locks['locked_fields']));
        $this->write($document, $locks);

        if (in_array('amount', $locks['locked_fields'], true)) {
            $data = is_array($document->extracted_data) ? $document->extracted_data : [];
            $interpretation = is_array($data['interpretation'] ?? null) ? $data['interpretation'] : [];
            $interpretation['expense_eligible'] = false;
            $data['interpretation'] = $interpretation;
            $document->extracted_data = $data;
        }
    }

    public function lockFiling(Document $document): void
    {
        $locks = $this->read($document);
        $locks['locked_filing'] = true;
        $this->write($document, $locks);
    }

    public function clearFieldLocks(Document $document): void
    {
        $locks = $this->read($document);
        $locks['locked_fields'] = [];
        $this->write($document, $locks);
    }

    /**
     * @param  array{locked_fields: list<string>, locked_filing: bool}  $locks
     * @param  array<string, mixed>  $extractedData
     * @return array<string, mixed>
     */
    public function mergeIntoExtractedData(array $extractedData, array $locks): array
    {
        $extractedData['_user'] = [
            'locked_fields' => array_values(array_unique($locks['locked_fields'])),
            'locked_filing' => $locks['locked_filing'],
        ];

        return $extractedData;
    }

    /**
     * @param  array{locked_fields: list<string>, locked_filing: bool}  $locks
     */
    private function write(Document $document, array $locks): void
    {
        $data = is_array($document->extracted_data) ? $document->extracted_data : [];
        $document->extracted_data = $this->mergeIntoExtractedData($data, $locks);
    }
}
