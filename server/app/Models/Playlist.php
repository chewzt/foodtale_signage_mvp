<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Playlist extends Model
{
    public const KIND_PLAYLIST = 'playlist';

    public const KIND_CAROUSEL = 'carousel';

    protected $fillable = ['name', 'kind', 'panel_count', 'start_at'];

    protected $casts = [
        'start_at' => 'datetime',
        'panel_count' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(PlaylistItem::class)
            ->orderByRaw('panel_index is null')
            ->orderBy('panel_index')
            ->orderBy('sort_order');
    }

    public function isCarousel(): bool
    {
        return $this->kind === self::KIND_CAROUSEL;
    }

    public function scopePlaylists($query)
    {
        return $query->where('kind', self::KIND_PLAYLIST);
    }

    public function scopeCarousels($query)
    {
        return $query->where('kind', self::KIND_CAROUSEL);
    }
}
