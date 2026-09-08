<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantasySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'fantasy_account_id',
        'key',
        'value',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FantasyAccount::class, 'fantasy_account_id');
    }
}
