<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileDeviceToken;
use App\Support\V3\SystemUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileDeviceTokenController extends Controller
{
    public function store(Request $request, SystemUnit $systemUnit): JsonResponse
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string', 'max:4096'],
            'installation_id' => ['required', 'uuid'],
            'device_name' => ['nullable', 'string', 'max:150'],
            'app_version' => ['nullable', 'string', 'max:30'],
            'platform' => ['nullable', 'in:android'],
        ]);

        $hash = hash('sha256', $data['fcm_token']);
        MobileDeviceToken::query()
            ->where('token_hash', $hash)
            ->where(function ($query) use ($request, $data): void {
                $query->where('user_id', '!=', $request->user()->getKey())
                    ->orWhere('installation_id', '!=', $data['installation_id']);
            })
            ->update(['is_active' => false]);

        $token = MobileDeviceToken::query()->updateOrCreate(
            [
                'user_id' => $request->user()->getKey(),
                'installation_id' => $data['installation_id'],
            ],
            [
                'sppg_unit_id' => $systemUnit->id(),
                'fcm_token' => $data['fcm_token'],
                'token_hash' => $hash,
                'platform' => $data['platform'] ?? 'android',
                'device_name' => $data['device_name'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'is_active' => true,
                'registered_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Perangkat berhasil didaftarkan untuk menerima notifikasi.',
            'data' => ['id' => $token->getKey(), 'registered' => true],
        ]);
    }

    public function destroy(Request $request, string $installationId): JsonResponse
    {
        validator(['installation_id' => $installationId], [
            'installation_id' => ['required', 'uuid'],
        ])->validate();

        MobileDeviceToken::query()
            ->where('user_id', $request->user()->getKey())
            ->where('installation_id', $installationId)
            ->update(['is_active' => false]);

        return response()->json(['message' => 'Notifikasi perangkat dinonaktifkan.']);
    }
}
