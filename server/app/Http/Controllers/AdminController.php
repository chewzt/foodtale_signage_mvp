<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Support\MediaDuration;
use App\Support\SignageDeviceClock;
use App\Support\SignageFit;
use App\Support\SignageTimeline;
use App\Support\SignageUpload;
use App\Support\SignageUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function index()
    {
        return view('admin', [
            'devices' => Device::with('playlist')->latest()->get(),
            'playlists' => Playlist::with('items')->latest()->get(),
            'serverUrl' => SignageUrl::lanBase(),
            'uploadMaxMb' => SignageUpload::maxMegabytes(),
        ]);
    }

    public function play()
    {
        return view('play');
    }

    public function deviceClocks()
    {
        return response()->json([
            'devices' => Device::query()->latest()->get()->map(fn (Device $device) => [
                'id' => $device->id,
                ...SignageDeviceClock::summary($device),
            ])->values(),
        ]);
    }

    public function demo()
    {
        $playlist = Playlist::with('items')->latest()->first();

        return view('demo', [
            'playlist' => $playlist,
        ]);
    }

    public function restartSync(Request $request, Playlist $playlist)
    {
        $playlist->update(['start_at' => SignageTimeline::origin()]);

        return $this->finished($request);
    }

    public function createPlaylist(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        Playlist::create([
            'name' => $data['name'],
            'start_at' => SignageTimeline::origin(),
        ]);

        return $this->finished($request);
    }

    public function uploadItem(Request $request, Playlist $playlist)
    {
        $data = $request->validate([
            'media' => ['required', 'file', 'mimes:mp4,jpg,jpeg,png,webp', 'max:'.SignageUpload::maxKilobytes()],
            'fit' => ['nullable', 'in:once,loop,cut'],
            'slot_seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        $file = $data['media'];
        $path = $file->store('signage', 'public');
        $absolute = Storage::disk('public')->path($path);

        $type = str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'image';
        $fileMs = $type === 'video' ? MediaDuration::probeMilliseconds($absolute) : null;

        $fit = $type === 'image' ? 'once' : ($data['fit'] ?? 'loop');
        $slotMs = (($data['slot_seconds'] ?? 10) * 1000);
        $durationMs = SignageFit::durationMs($fit, $fileMs, $slotMs);

        $playlist->items()->create([
            'type' => $type,
            'path' => $path,
            'duration_ms' => $durationMs,
            'fit' => $fit,
            'file_duration_ms' => $fileMs,
            'sort_order' => ($playlist->items()->max('sort_order') ?? 0) + 1,
        ]);

        $playlist->update(['start_at' => SignageTimeline::origin()]);

        return $this->finished($request);
    }

    public function updateItem(Request $request, Playlist $playlist, PlaylistItem $item)
    {
        abort_unless($item->playlist_id === $playlist->id, 404);

        $data = $request->validate([
            'fit' => ['required', 'in:once,loop,cut'],
            'slot_seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        $fileMs = $item->file_duration_ms;
        if ($item->type === 'video' && $fileMs === null) {
            $fileMs = MediaDuration::probeMilliseconds(Storage::disk('public')->path($item->path));
        }

        $fit = $item->type === 'image' ? 'once' : $data['fit'];
        $slotMs = (($data['slot_seconds'] ?? 10) * 1000);

        $item->update([
            'fit' => $fit,
            'file_duration_ms' => $fileMs,
            'duration_ms' => SignageFit::durationMs($fit, $fileMs, $slotMs),
        ]);

        $playlist->update(['start_at' => SignageTimeline::origin()]);

        return $this->finished($request);
    }

    public function deleteItem(Request $request, Playlist $playlist, PlaylistItem $item)
    {
        abort_unless($item->playlist_id === $playlist->id, 404);

        Storage::disk('public')->delete($item->path);
        $item->delete();
        $playlist->update(['start_at' => SignageTimeline::origin()]);

        return $this->finished($request);
    }

    public function createPairingCode(Request $request)
    {
        Device::create([
            'name' => 'Unpaired TV',
            'pairing_code' => strtoupper(Str::random(6)),
            'status' => 'waiting',
        ]);

        return $this->finished($request);
    }

    public function assignPlaylist(Request $request, Device $device)
    {
        $data = $request->validate([
            'playlist_id' => ['required', 'exists:playlists,id'],
        ]);

        $device->update(['playlist_id' => $data['playlist_id']]);
        $device->playlist?->update(['start_at' => SignageTimeline::origin()]);

        return $this->finished($request);
    }

    public function unassignPlaylist(Request $request, Device $device)
    {
        $device->update(['playlist_id' => null]);

        return $this->finished($request);
    }

    public function resetDevice(Request $request, Device $device)
    {
        $device->update([
            'device_token' => null,
            'playlist_id' => null,
            'status' => 'waiting',
            'last_seen_at' => null,
            'name' => 'Unpaired TV',
        ]);

        return $this->finished($request);
    }

    private function finished(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}
