<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = [
        'rate_date',
        'from_currency',
        'to_currency',
        'rate',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate' => 'float',
            'fetched_at' => 'datetime',
        ];
    }
}
