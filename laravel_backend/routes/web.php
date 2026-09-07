<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;

Route::get('/admin', [AdminController::class, 'index']);
Route::post('/admin/playlists', [AdminController::class, 'createPlaylist']);
Route::post('/admin/playlists/{playlist}/items', [AdminController::class, 'uploadItem']);
Route::post('/admin/playlists/{playlist}/items/{item}/delete', [AdminController::class, 'deleteItem']);
Route::post('/admin/devices', [AdminController::class, 'createPairingCode']);
Route::post('/admin/devices/{device}/assign', [AdminController::class, 'assignPlaylist']);
