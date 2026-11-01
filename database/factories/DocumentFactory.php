<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'profile_id' => Profile::factory(),
            'status' => DocumentStatus::NeedsReview,
            'original_filename' => 'receipt.jpg',
            'storage_path' => 'documents/test/receipt.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'title' => 'Test receipt',
        ];
    }
}
