<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Support\SignageManifest;
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

        $playlist = $device->playlist()->with('items')->first();

        if (!$playlist) {
            return response()->json([
                'playlist_name' => 'Unassigned',
                'start_at' => now()->utc()->toIso8601String(),
                'items' => [],
            ]);
        }

        return response()->json([
            'playlist_name' => $playlist->name,
            'start_at' => ($playlist->start_at ?? now())
                ->utc()->toIso8601String(),
            'items' => $playlist->items->map(fn ($item) => SignageManifest::item($item))->values(),
        ]);
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
}
