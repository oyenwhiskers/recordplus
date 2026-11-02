<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'name',
        'sort_order',
    ];

    public static function nextSortOrder(int $profileId): int
    {
        $max = static::query()->where('profile_id', $profileId)->max('sort_order');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
