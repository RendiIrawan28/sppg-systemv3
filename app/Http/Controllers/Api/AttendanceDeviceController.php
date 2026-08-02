<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AttendanceDeviceAuthenticator;
use App\Services\VolunteerAttendanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceDeviceController extends Controller
{
    public function configuration(Request $request, AttendanceDeviceAuthenticator $authenticator, VolunteerAttendanceService $attendance): JsonResponse
    {
        $device = $authenticator->authenticate($request);
        if (! $device) {
            return response()->json(['status' => 'error', 'action' => 'unauthorized', 'message' => 'Perangkat tidak dikenali.'], 401);
        }

        return response()->json($attendance->configuration($device));
    }

    public function tap(Request $request, AttendanceDeviceAuthenticator $authenticator, VolunteerAttendanceService $attendance): JsonResponse
    {
        $device = $authenticator->authenticate($request);
        if (! $device) {
            return response()->json(['status' => 'error', 'action' => 'unauthorized', 'message' => 'Perangkat tidak dikenali.'], 401);
        }

        $data = $request->validate([
            'uid_kartu' => ['required', 'string', 'max:50'],
            'request_id' => ['required', 'string', 'max:80'],
            'tapped_at' => ['nullable', 'date'],
            'offline' => ['nullable', 'boolean'],
        ]);

        $payload = $attendance->recordTap(
            device: $device,
            uid: $data['uid_kartu'],
            requestId: $data['request_id'],
            tappedAt: filled($data['tapped_at'] ?? null) ? Carbon::parse($data['tapped_at']) : null,
            offline: (bool) ($data['offline'] ?? false),
        );

        return response()->json($payload, ($payload['action'] ?? null) === 'uid_not_found' ? 404 : 200);
    }
}
