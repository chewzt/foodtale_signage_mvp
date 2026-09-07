<?php

namespace App\Support;

use App\Models\PlaylistItem;

class SignageManifest
{
    /**
     * @return array{id:int, type:string, url:string, duration_ms:int, fit:string, file_duration_ms:int|null}
     */
    public static function item(PlaylistItem $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'url' => SignageUrl::media($item->path),
            'duration_ms' => $item->duration_ms,
            'fit' => $item->fit ?: 'loop',
            'file_duration_ms' => $item->file_duration_ms,
        ];
    }
}
