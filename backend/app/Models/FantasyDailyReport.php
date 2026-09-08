<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FantasyDailyReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'fantasy_account_id',
        'report_date',
        'summary',
        'top_actions',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'summary' => 'array',
            'top_actions' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FantasyAccount::class, 'fantasy_account_id');
    }
}
