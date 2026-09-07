<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Device extends Model
{
    protected $fillable = [
        'name', 'pairing_code', 'device_token', 'playlist_id',
        'last_seen_at', 'status'
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public static function makeToken(): string
    {
        return Str::random(64);
    }

    public function playlist()
    {
        return $this->belongsTo(Playlist::class);
    }
}
