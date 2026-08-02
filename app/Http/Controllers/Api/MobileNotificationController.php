<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileDeviceToken;
use App\Models\MobileNotification;
use App\Services\Mobile\FcmHttpV1Client;
use App\Services\Mobile\MobilePushService;
use App\Support\V3\SystemUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = MobileNotification::query()
            ->where('user_id', $request->user()->getKey())
            ->where('notification_type', '!=', 'fcm_test')
            ->latest('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $notifications->map(fn (MobileNotification $notification): array => [
                'id' => $notification->getKey(),
                'title' => $notification->title,
                'body' => $notification->body,
                'type' => $notification->notification_type,
                'channel' => $notification->channel,
                'screen' => $notification->screen,
                'payload' => $notification->payload ?? [],
                'delivery_status' => $notification->delivery_status,
                'error_message' => $notification->error_message,
                'created_at' => $notification->created_at?->toIso8601String(),
                'read_at' => $notification->read_at?->toIso8601String(),
            ]),
            'meta' => [
                'unread_count' => $notifications->filter(fn (MobileNotification $item): bool => $item->read_at === null)->count(),
            ],
        ]);
    }

    public function status(
        Request $request,
        FcmHttpV1Client $fcm,
    ): JsonResponse {
        $data = $request->validate([
            'installation_id' => ['required', 'uuid'],
        ]);

        $device = MobileDeviceToken::query()
            ->where('user_id', $request->user()->getKey())
            ->where('installation_id', $data['installation_id'])
            ->latest('id')
            ->first();
        $configuration = $fcm->configurationStatus();

        return response()->json([
            'data' => [
                'firebase_configured' => $configuration['configured'],
                'firebase_message' => $configuration['message'],
                'device_registered' => $device !== null,
                'device_active' => (bool) ($device?->is_active ?? false),
                'device_name' => $device?->device_name,
                'app_version' => $device?->app_version,
                'registered_at' => $device?->registered_at?->toIso8601String(),
                'last_seen_at' => $device?->last_seen_at?->toIso8601String(),
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    public function test(
        Request $request,
        SystemUnit $systemUnit,
        MobilePushService $push,
    ): JsonResponse {
        $data = $request->validate([
            'installation_id' => ['required', 'uuid'],
        ]);

        $notification = $push->sendTestNotification(
            user: $request->user(),
            unitId: $systemUnit->id(),
            installationId: $data['installation_id'],
        );

        return response()->json([
            'message' => match ($notification->delivery_status) {
                'sent' => 'Notifikasi uji berhasil diterima oleh Firebase.',
                'no_device' => 'Perangkat ini belum terdaftar pada server.',
                'not_configured' => 'Firebase pada server belum siap digunakan.',
                default => 'Notifikasi uji belum berhasil dikirim.',
            },
            'data' => [
                'notification_id' => $notification->getKey(),
                'delivery_status' => $notification->delivery_status,
                'error_message' => $notification->error_message,
                'sent_at' => $notification->sent_at?->toIso8601String(),
            ],
        ]);
    }

    public function read(Request $request, MobileNotification $notification): JsonResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->getKey(), 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return response()->json(['message' => 'Notifikasi ditandai sudah dibaca.']);
    }

    public function readAll(Request $request): JsonResponse
    {
        MobileNotification::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Semua notifikasi ditandai sudah dibaca.']);
    }
}
