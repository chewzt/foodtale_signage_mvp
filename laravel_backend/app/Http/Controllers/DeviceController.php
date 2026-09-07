<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        $device->update(['last_seen_at' => now(), 'status' => 'online']);

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
            'items' => $playlist->items->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'url' => url(Storage::url($item->path)),
                'duration_ms' => $item->duration_ms,
            ])->values(),
        ]);
    }

    public function heartbeat(Request $request)
    {
        $device = $this->device($request);
        $device->update(['last_seen_at' => now(), 'status' => 'online']);
        return response()->json(['ok' => true]);
    }
}
