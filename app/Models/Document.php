<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Services\DocumentCapabilities;
use App\Services\DocumentIdentity;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $appends = [
        'display_primary',
        'display_secondary',
        'display_facts',
        'display_amount',
        'display_amount_label',
        'capabilities',
    ];

    protected $fillable = [
        'user_id',
        'profile_id',
        'category_id',
        'status',
        'document_type',
        'title',
        'document_date',
        'merchant',
        'reference_number',
        'amount',
        'currency',
        'extracted_data',
        'suggested_category_name',
        'suggests_new_category',
        'original_filename',
        'storage_path',
        'mime_type',
        'file_size',
        'ai_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'document_date' => 'date',
            'amount' => 'decimal:2',
            'extracted_data' => 'array',
            'suggests_new_category' => 'boolean',
            'file_size' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(DocumentReminder::class);
    }

    public function getDisplayPrimaryAttribute(): string
    {
        return app(DocumentIdentity::class)->primary($this);
    }

    public function getDisplaySecondaryAttribute(): ?string
    {
        return app(DocumentIdentity::class)->secondary($this);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function getDisplayFactsAttribute(): array
    {
        return app(DocumentIdentity::class)->facts($this);
    }

    public function getDisplayAmountAttribute(): ?string
    {
        return app(DocumentIdentity::class)->displayAmount($this);
    }

    public function getDisplayAmountLabelAttribute(): ?string
    {
        return app(DocumentIdentity::class)->displayAmountLabel($this);
    }

    /**
     * @return array{
     *     has_expiry: bool,
     *     has_money: bool,
     *     expense_eligible: bool,
     *     has_coverage: bool,
     *     has_balance: bool,
     *     expiry_date: ?string,
     *     expiry_role: ?string
     * }
     */
    public function getCapabilitiesAttribute(): array
    {
        return app(DocumentCapabilities::class)->for($this);
    }
}
