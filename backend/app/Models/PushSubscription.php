<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One browser/device that receives this account's notifications as Web Push. */
class PushSubscription extends Model
{
    protected $fillable = ['fantasy_account_id', 'endpoint_hash', 'endpoint', 'public_key', 'auth_token', 'content_encoding', 'user_agent'];

    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FantasyAccount::class, 'fantasy_account_id');
    }
}
