<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Playlist extends Model
{
    protected $fillable = ['name', 'start_at'];

    protected $casts = ['start_at' => 'datetime'];

    public function items()
    {
        return $this->hasMany(PlaylistItem::class)->orderBy('sort_order');
    }
}
