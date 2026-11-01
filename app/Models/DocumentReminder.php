<?php

namespace App\Models;

use App\Enums\ReminderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentReminder extends Model
{
    protected $fillable = [
        'document_id',
        'profile_id',
        'user_id',
        'date_role',
        'source_date',
        'remind_on',
        'status',
        'channel',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'source_date' => 'date',
            'remind_on' => 'date',
            'status' => ReminderStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
