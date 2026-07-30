<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = MobileNotification::query()
            ->where('user_id', $request->user()->getKey())
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
                'created_at' => $notification->created_at?->toIso8601String(),
                'read_at' => $notification->read_at?->toIso8601String(),
            ]),
            'meta' => [
                'unread_count' => $notifications->filter(fn (MobileNotification $item): bool => $item->read_at === null)->count(),
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
