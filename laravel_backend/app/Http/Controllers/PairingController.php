<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;

class PairingController extends Controller
{
    public function pair(Request $request)
    {
        $validated = $request->validate([
            'pairing_code' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $device = Device::where('pairing_code', $validated['pairing_code'])
            ->whereNull('device_token')
            ->firstOrFail();

        $device->update([
            'name' => $validated['device_name'],
            'device_token' => Device::makeToken(),
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        return response()->json([
            'device_token' => $device->device_token,
        ]);
    }
}
