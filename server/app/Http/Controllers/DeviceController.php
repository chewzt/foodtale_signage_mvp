<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Support\SignageManifest;
use App\Support\SignageNtp;
use App\Support\SignagePeers;
use App\Support\SignageTimeline;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    private function device(Request $request): Device
    {
        $header = $request->header('Authorization', '');
        abort_unless(str_starts_with($header, 'Bearer '), 401);
        $token = substr($header, 7);

        return Device::where('device_token', $token)->firstOrFail();
    }

    public function manifest(Request $request)
    {
        $device = $this->device($request);
        $this->touchDevice($request, $device);

        $playlist = $device->playlist()->with('items')->first()
            ?? SignagePeers::claimPlaylist($device)?->load('items');

        if (! $playlist) {
            $body = [
                'playlist_name' => 'Unassigned',
                'playlist_id' => 0,
                'device_id' => $device->id,
                'kind' => 'playlist',
                'panel_count' => 1,
                'panel_index' => 0,
                'peer_count' => 1,
                'peers' => [[
                    'id' => $device->id,
                    'name' => $device->name,
                ]],
                'start_at' => now()->utc()->toIso8601String(),
                'items' => [],
            ];
            $etag = '"'.sha1(json_encode($body, JSON_THROW_ON_ERROR)).'"';

            return $this->manifestResponse($request, $etag, $body);
        }

        $device->refresh();
        $peers = SignagePeers::roster($playlist, $device);
        $panelCount = max(1, (int) $playlist->panel_count);
        $panelIndex = min($panelCount - 1, max(0, (int) $device->panel_index));
        $items = $playlist->items;
        if ($playlist->isCarousel()) {
            $items = $items->where('panel_index', $panelIndex)->values();
        }

        $pack = SignageManifest::forDevice($device, $playlist, $items, $peers);

        return $this->manifestResponse($request, $pack['etag'], $pack['body']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function manifestResponse(Request $request, string $etag, array $body)
    {
        $incoming = trim((string) $request->header('If-None-Match', ''));
        if ($incoming !== '' && $incoming === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response()->json($body)->header('ETag', $etag);
    }

    public function heartbeat(Request $request)
    {
        $device = $this->device($request);
        $this->touchDevice($request, $device);

        return response()->json(['ok' => true]);
    }

    private function touchDevice(Request $request, Device $device): void
    {
        $patch = [
            'last_seen_at' => now(),
            'status' => 'online',
        ];

        if ($request->exists('clock_offset_ms')) {
            $patch['clock_offset_ms'] = max(-86_400_000, min(86_400_000, (int) $request->input('clock_offset_ms')));
            $patch['clock_rtt_ms'] = max(0, min(60_000, (int) $request->input('clock_rtt_ms', 0)));
            $patch['clock_synced_at'] = now();
        }

        $device->update($patch);
    }

    public function syncConfirm(Request $request)
    {
        $device = $this->device($request);
        $this->touchDevice($request, $device);

        $playlist = $device->playlist()->with('items')->first();
        if (! $playlist) {
            return response()->json([
                ...SignageNtp::payload($request->query('t0')),
                'start_at' => now()->utc()->toIso8601String(),
                'next_cut_at' => now()->utc()->toIso8601String(),
                'remaining_ms' => 0,
                'cycle_ms' => 0,
                'index' => -1,
                'confirm_id' => 'unassigned',
            ]);
        }

        $device->refresh();
        $panelCount = max(1, (int) $playlist->panel_count);
        $panelIndex = min($panelCount - 1, max(0, (int) $device->panel_index));
        $items = $playlist->items;
        if ($playlist->isCarousel()) {
            $items = $items->where('panel_index', $panelIndex)->values();
        }

        $origin = ($playlist->start_at ?? now())->utc();
        $cut = SignageTimeline::nextBoundary(
            $origin,
            $items->map(fn ($item) => (int) $item->duration_ms)->all(),
        );
        $nextCut = $cut['next_cut_at']->utc();

        $device->update(['clock_synced_at' => now()]);

        return response()->json([
            ...SignageNtp::payload($request->query('t0')),
            'start_at' => $origin->toIso8601String(),
            'next_cut_at' => $nextCut->toIso8601String(),
            'remaining_ms' => $cut['remaining_ms'],
            'cycle_ms' => $cut['cycle_ms'],
            'index' => $cut['index'],
            'confirm_id' => $origin->toIso8601String().'|'.SignageTimeline::epochMs($nextCut),
        ]);
    }
}
