<?php

use App\Http\Controllers\DeviceController;
use App\Http\Controllers\PairingController;
use App\Models\Playlist;
use App\Support\SignageManifest;
use App\Support\SignageNtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/time', function (Request $request) {
    return response()->json(SignageNtp::payload($request->query('t0')));
});

Route::post('/pair', [PairingController::class, 'pair']);
Route::get('/device/manifest', [DeviceController::class, 'manifest']);
Route::post('/device/heartbeat', [DeviceController::class, 'heartbeat']);

Route::get('/demo/playlist', function () {
    $playlist = Playlist::with('items')->latest()->first();

    if (! $playlist) {
        return response()->json([
            'playlist_name' => 'Unassigned',
            'start_at' => now()->utc()->toIso8601String(),
            'items' => [],
        ]);
    }

    return response()->json([
        'playlist_name' => $playlist->name,
        'start_at' => ($playlist->start_at ?? now())->utc()->toIso8601String(),
        'items' => $playlist->items->map(fn ($item) => SignageManifest::item($item))->values(),
    ]);
});
