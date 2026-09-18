<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Device extends Model
{
    protected $fillable = [
        'name', 'pairing_code', 'device_token', 'playlist_id', 'panel_index',
        'last_seen_at', 'status', 'clock_offset_ms', 'clock_rtt_ms', 'clock_synced_at',
        'play_index', 'play_item_id', 'play_decoder_ms', 'play_lag_ms', 'play_playing',
        'resync_until',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'clock_synced_at' => 'datetime',
        'resync_until' => 'datetime',
        'panel_index' => 'integer',
        'play_playing' => 'boolean',
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
