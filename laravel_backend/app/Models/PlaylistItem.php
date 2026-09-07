<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlaylistItem extends Model
{
    protected $fillable = [
        'playlist_id', 'type', 'path', 'duration_ms', 'sort_order'
    ];

    public function playlist()
    {
        return $this->belongsTo(Playlist::class);
    }
}
