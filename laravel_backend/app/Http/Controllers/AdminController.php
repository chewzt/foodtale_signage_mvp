<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
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
        ]);
    }

    public function createPlaylist(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        Playlist::create([
            'name' => $data['name'],
            'start_at' => now()->addSeconds(10),
        ]);

        return back();
    }

    public function uploadItem(Request $request, Playlist $playlist)
    {
        $data = $request->validate([
            'media' => ['required', 'file', 'mimes:mp4,jpg,jpeg,png,webp'],
            'duration_ms' => ['nullable', 'integer', 'min:1000'],
        ]);

        $file = $data['media'];
        $path = $file->store('signage', 'public');

        $type = str_starts_with($file->getMimeType(), 'video/') ? 'video' : 'image';

        $playlist->items()->create([
            'type' => $type,
            'path' => $path,
            'duration_ms' => $data['duration_ms'] ?? ($type === 'video' ? 30000 : 10000),
            'sort_order' => ($playlist->items()->max('sort_order') ?? 0) + 1,
        ]);

        $playlist->update(['start_at' => now()->addSeconds(10)]);

        return back();
    }

    public function deleteItem(Playlist $playlist, PlaylistItem $item)
    {
        abort_unless($item->playlist_id === $playlist->id, 404);

        Storage::disk('public')->delete($item->path);
        $item->delete();
        $playlist->update(['start_at' => now()->addSeconds(10)]);

        return back();
    }

    public function createPairingCode(Request $request)
    {
        Device::create([
            'name' => 'Unpaired TV',
            'pairing_code' => strtoupper(Str::random(6)),
            'status' => 'waiting',
        ]);

        return back();
    }

    public function assignPlaylist(Request $request, Device $device)
    {
        $data = $request->validate([
            'playlist_id' => ['required', 'exists:playlists,id'],
        ]);

        $device->update(['playlist_id' => $data['playlist_id']]);
        $device->playlist?->update(['start_at' => now()->addSeconds(10)]);

        return back();
    }
}
