<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FantasyClub extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'name',
        'short_name',
        'badge_url',
    ];
}
