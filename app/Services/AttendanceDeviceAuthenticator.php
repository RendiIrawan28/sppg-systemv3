<?php

namespace App\Services;

use App\Models\AttendanceDevice;
use Illuminate\Http\Request;

class AttendanceDeviceAuthenticator
{
    public function authenticate(Request $request): ?AttendanceDevice
    {
        $code = trim((string) ($request->header('X-Device-Code') ?: $request->input('device_code')));
        $key = trim((string) ($request->header('X-Device-Key') ?: $request->input('device_key')));

        if ($code === '' || $key === '') {
            return null;
        }

        $device = AttendanceDevice::query()->where('code', $code)->where('is_active', true)->first();
        if (! $device || ! hash_equals($device->secret_hash, hash('sha256', $key))) {
            return null;
        }

        $device->forceFill([
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'firmware_version' => $request->header('X-Firmware-Version') ?: $device->firmware_version,
        ])->save();

        return $device;
    }
}
