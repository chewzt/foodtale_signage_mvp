<?php

use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

Route::get('/admin', [AdminController::class, 'index']);
Route::get('/admin/device-clocks', [AdminController::class, 'deviceClocks']);
Route::get('/demo', [AdminController::class, 'demo']);
Route::get('/play', [AdminController::class, 'play']);
Route::post('/admin/playlists', [AdminController::class, 'createPlaylist']);
Route::post('/admin/playlists/{playlist}/items', [AdminController::class, 'uploadItem']);
Route::post('/admin/playlists/{playlist}/items/{item}', [AdminController::class, 'updateItem']);
Route::post('/admin/playlists/{playlist}/items/{item}/delete', [AdminController::class, 'deleteItem']);
Route::post('/admin/playlists/{playlist}/restart', [AdminController::class, 'restartSync']);
Route::post('/admin/devices', [AdminController::class, 'createPairingCode']);
Route::post('/admin/devices/{device}/assign', [AdminController::class, 'assignPlaylist']);
Route::post('/admin/devices/{device}/unassign', [AdminController::class, 'unassignPlaylist']);
Route::post('/admin/devices/{device}/reset', [AdminController::class, 'resetDevice']);
