<?php

namespace App\Support;

use App\Models\Device;
use App\Models\Playlist;
use Illuminate\Support\Collection;

class SignageManifest
{
    /**
     * @return array{id:int, type:string, url:string, duration_ms:int, fit:string, file_duration_ms:int|null}
     */
    public static function item(\App\Models\PlaylistItem $item): array
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

    /**
     * @param  Collection<int, \App\Models\PlaylistItem>  $items
     * @return array{etag: string, body: array<string, mixed>}
     */
    public static function forDevice(Device $device, Playlist $playlist, Collection $items, array $peers): array
    {
        $panelCount = max(1, (int) $playlist->panel_count);
        $panelIndex = min($panelCount - 1, max(0, (int) $device->panel_index));
        $startAt = ($playlist->start_at ?? now())->utc()->toIso8601String();
        $mapped = $items->map(fn ($item) => self::item($item))->values()->all();

        $body = [
            'playlist_name' => $playlist->name,
            'playlist_id' => $playlist->id,
            'device_id' => $device->id,
            'kind' => $playlist->kind ?: 'playlist',
            'panel_count' => $panelCount,
            'panel_index' => $panelIndex,
            'peer_count' => $peers['peer_count'],
            'peers' => $peers['peers'],
            'start_at' => $startAt,
            'items' => $mapped,
        ];

        $etagSource = json_encode([
            $playlist->id,
            $startAt,
            $panelCount,
            $panelIndex,
            $mapped,
        ], JSON_THROW_ON_ERROR);

        return [
            'etag' => '"'.sha1($etagSource).'"',
            'body' => $body,
        ];
    }
}
