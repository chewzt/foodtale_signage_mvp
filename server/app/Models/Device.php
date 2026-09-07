<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Device extends Model
{
    protected $fillable = [
        'name', 'pairing_code', 'device_token', 'playlist_id', 'panel_index',
        'last_seen_at', 'status', 'clock_offset_ms', 'clock_rtt_ms', 'clock_synced_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'clock_synced_at' => 'datetime',
        'panel_index' => 'integer',
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
