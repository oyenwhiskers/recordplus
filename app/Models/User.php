<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'google_id', 'password', 'active_profile_id', 'ai_enabled', 'is_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ai_enabled' => 'boolean',
            'is_admin' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    public function activeProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'active_profile_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }
}
