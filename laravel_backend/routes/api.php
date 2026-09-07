<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PairingController;
use App\Http\Controllers\DeviceController;

Route::get('/time', function () {
    return response()->json(['utc' => now()->utc()->toIso8601String()]);
});

Route::post('/pair', [PairingController::class, 'pair']);
Route::get('/device/manifest', [DeviceController::class, 'manifest']);
Route::post('/device/heartbeat', [DeviceController::class, 'heartbeat']);
