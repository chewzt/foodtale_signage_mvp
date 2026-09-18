<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Support\MediaDuration;
use App\Support\SignageApk;
use App\Support\SignageDeviceClock;
use App\Support\SignageFit;
use App\Support\SignageTimeline;
use App\Support\SignageUpload;
use App\Support\SignageUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function index()
    {
        $all = Playlist::with('items')->latest()->get();

        return view('admin', [
            'devices' => Device::with('playlist')->latest()->get(),
            'playlists' => $all->where('kind', Playlist::KIND_PLAYLIST)->values(),
            'carousels' => $all->where('kind', Playlist::KIND_CAROUSEL)->values(),
            'assignTargets' => $all,
            'serverUrl' => SignageUrl::lanBase(),
            'apkUrl' => SignageUrl::lanBase().'/'.SignageApk::FILENAME,
            'apkVersion' => SignageApk::meta()['version'],
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
        $playlist = Playlist::with('items')->playlists()->latest()->first();

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
            'kind' => Playlist::KIND_PLAYLIST,
            'panel_count' => 1,
            'start_at' => SignageTimeline::origin(),
        ]);

        return $this->finished($request);
    }

    public function createCarousel(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'panel_count' => ['required', 'integer', 'min:2', 'max:8'],
        ]);

        Playlist::create([
            'name' => $data['name'],
            'kind' => Playlist::KIND_CAROUSEL,
            'panel_count' => $data['panel_count'],
            'start_at' => SignageTimeline::origin(),
        ]);

        return $this->finished($request);
    }

    public function uploadItem(Request $request, Playlist $playlist)
    {
        SignageUpload::rejectIfPhpRejected($request);

        if ($playlist->isCarousel()) {
            $data = $request->validate([
                'media' => ['required', 'file', 'max:'.SignageUpload::maxKilobytes()],
                'panel_index' => ['required', 'integer', 'min:0', 'max:7'],
                'fit' => ['nullable', 'in:once,loop,cut'],
                'slot_seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
            ]);
            if ((int) $data['panel_index'] >= $playlist->panel_count) {
                throw ValidationException::withMessages([
                    'panel_index' => 'This carousel only has '.$playlist->panel_count.' panels.',
                ]);
            }
        } else {
            $data = $request->validate([
                'media' => ['required', 'file', 'max:'.SignageUpload::maxKilobytes()],
                'fit' => ['nullable', 'in:once,loop,cut'],
                'slot_seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
            ]);
        }

        $file = $data['media'];
        $type = SignageUpload::assertAccepted($file, $playlist->isCarousel());
        $path = SignageUpload::storePlayable($file, $type);
        $absolute = Storage::disk('public')->path($path);
        $fileMs = $type === 'video' ? MediaDuration::probeMilliseconds($absolute) : null;
        $fit = $type === 'image' ? 'once' : ($data['fit'] ?? 'loop');
        $slotMs = (($data['slot_seconds'] ?? 10) * 1000);
        $durationMs = SignageFit::durationMs($fit, $fileMs, $slotMs);

        $panelIndex = $playlist->isCarousel() ? (int) $data['panel_index'] : null;
        if ($playlist->isCarousel()) {
            $old = $playlist->items()->where('panel_index', $panelIndex)->get();
            foreach ($old as $item) {
                Storage::disk('public')->delete($item->path);
                $item->delete();
            }
        }

        $playlist->items()->create([
            'type' => $type,
            'path' => $path,
            'duration_ms' => $durationMs,
            'fit' => $fit,
            'file_duration_ms' => $fileMs,
            'sort_order' => $panelIndex !== null
                ? $panelIndex + 1
                : ($playlist->items()->max('sort_order') ?? 0) + 1,
            'panel_index' => $panelIndex,
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

    public function deletePlaylist(Request $request, Playlist $playlist)
    {
        foreach ($playlist->items as $item) {
            Storage::disk('public')->delete($item->path);
        }

        Device::query()->where('playlist_id', $playlist->id)->update([
            'playlist_id' => null,
            'panel_index' => 0,
        ]);

        $playlist->delete();

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
            'panel_index' => ['nullable', 'integer', 'min:0', 'max:7'],
        ]);

        $playlist = Playlist::query()->findOrFail($data['playlist_id']);
        $panelIndex = 0;

        if ($playlist->isCarousel()) {
            $panelIndex = (int) ($data['panel_index'] ?? 0);
            if ($panelIndex < 0 || $panelIndex >= $playlist->panel_count) {
                throw ValidationException::withMessages([
                    'panel_index' => 'Pick part 1–'.$playlist->panel_count.' for this carousel.',
                ]);
            }
        }

        $device->update([
            'playlist_id' => $playlist->id,
            'panel_index' => $panelIndex,
        ]);
        $playlist->update(['start_at' => SignageTimeline::origin()]);

        return $this->finished($request);
    }

    public function unassignPlaylist(Request $request, Device $device)
    {
        $device->update([
            'playlist_id' => null,
            'panel_index' => 0,
        ]);

        return $this->finished($request);
    }

    public function resetDevice(Request $request, Device $device)
    {
        $device->update([
            'device_token' => null,
            'playlist_id' => null,
            'panel_index' => 0,
            'status' => 'waiting',
            'last_seen_at' => null,
            'name' => 'Unpaired TV',
        ]);

        return $this->finished($request);
    }

    public function deleteDevice(Request $request, Device $device)
    {
        $device->delete();

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
