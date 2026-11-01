<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilingPreference extends Model
{
    protected $fillable = [
        'profile_id',
        'document_type',
        'merchant_normalized',
        'category_id',
        'confirmation_count',
    ];

    protected function casts(): array
    {
        return [
            'confirmation_count' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
