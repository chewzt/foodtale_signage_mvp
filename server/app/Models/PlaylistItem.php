<?php

namespace App\Models;

use App\Support\SignageUrl;
use Illuminate\Database\Eloquent\Model;

class PlaylistItem extends Model
{
    protected $fillable = [
        'playlist_id', 'type', 'path', 'duration_ms', 'fit', 'file_duration_ms', 'sort_order', 'panel_index',
    ];

    protected $casts = [
        'panel_index' => 'integer',
        'duration_ms' => 'integer',
        'file_duration_ms' => 'integer',
        'sort_order' => 'integer',
    ];

    public function playlist()
    {
        return $this->belongsTo(Playlist::class);
    }

    public function mediaUrl(): string
    {
        return SignageUrl::media($this->path);
    }
}
