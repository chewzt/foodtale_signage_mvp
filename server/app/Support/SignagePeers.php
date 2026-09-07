<?php

namespace App\Support;

use App\Models\Device;
use App\Models\Playlist;

class SignagePeers
{
    /**
     * Put an unpaired device on the playlist the other screens already use.
     * Pairing alone does not join the group — assignment does.
     * Never auto-claim a carousel; wall panels must be assigned in admin.
     */
    public static function claimPlaylist(Device $device): ?Playlist
    {
        if ($device->playlist_id) {
            return $device->playlist()->first();
        }

        $playlistId = Device::query()
            ->whereNotNull('playlist_id')
            ->whereNotNull('device_token')
            ->whereHas('playlist', fn ($q) => $q->where('kind', Playlist::KIND_PLAYLIST))
            ->latest('last_seen_at')
            ->value('playlist_id')
            ?: Playlist::query()->playlists()->latest('id')->value('id');

        if (! $playlistId) {
            return null;
        }

        $device->update([
            'playlist_id' => $playlistId,
            'panel_index' => 0,
        ]);
        $playlist = Playlist::query()->find($playlistId);
        $playlist?->update(['start_at' => SignageTimeline::origin()]);

        return $playlist;
    }

    /**
     * HTTP roster for a playlist. Browsers cannot join the Android UDP group,
     * so this is the actual peer list every player should display.
     *
     * @return array{peer_count:int, peers:list<array{id:int, name:string}>}
     */
    public static function roster(Playlist $playlist, ?Device $self = null): array
    {
        $devices = Device::query()
            ->where('playlist_id', $playlist->id)
            ->whereNotNull('device_token')
            ->where('last_seen_at', '>=', now()->subSeconds(90))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($self && (int) $self->playlist_id === (int) $playlist->id && ! $devices->contains('id', $self->id)) {
            $devices->push($self);
            $devices = $devices->sortBy('id')->values();
        }

        return [
            'peer_count' => max(1, $devices->count()),
            'peers' => $devices->map(fn (Device $device) => [
                'id' => $device->id,
                'name' => $device->name,
            ])->values()->all(),
        ];
    }
}
