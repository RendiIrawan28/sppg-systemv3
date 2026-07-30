<?php

namespace App\Services\Mobile;

use App\Models\MobileDeviceToken;
use App\Models\MobileNotification;
use App\Models\MobileTask;
use Illuminate\Http\Client\RequestException;
use Throwable;

class MobilePushService
{
    public function __construct(private readonly FcmHttpV1Client $fcm) {}

    public function notifyTask(MobileTask $task, string $stage, string $title, string $body): MobileNotification
    {
        $dedupeKey = hash('sha256', "task-notification:{$task->getKey()}:{$stage}");
        $notification = MobileNotification::query()->firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'sppg_unit_id' => $task->sppg_unit_id,
                'user_id' => $task->user_id,
                'mobile_task_id' => $task->getKey(),
                'notification_type' => $stage,
                'title' => $title,
                'body' => $body,
                'channel' => $task->channel,
                'screen' => $task->screen,
                'payload' => $task->payload,
                'delivery_status' => 'pending',
            ],
        );

        if ($notification->delivery_status === 'sent') {
            return $notification;
        }

        $tokens = MobileDeviceToken::query()
            ->where('user_id', $task->user_id)
            ->active()
            ->get();

        if ($tokens->isEmpty()) {
            $notification->update([
                'delivery_status' => 'no_device',
                'failed_at' => now(),
                'error_message' => 'Pengguna belum mempunyai perangkat aktif.',
            ]);

            return $notification->refresh();
        }

        if (! config('mobile.firebase.enabled')) {
            $notification->update([
                'delivery_status' => 'not_configured',
                'failed_at' => now(),
                'error_message' => 'Firebase belum diaktifkan pada server.',
            ]);

            return $notification->refresh();
        }

        $sent = 0;
        $firstMessageId = null;
        $errors = [];
        foreach ($tokens as $token) {
            try {
                $response = $this->fcm->send($token->fcm_token, [
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => collect([
                        'notification_id' => (string) $notification->getKey(),
                        'task_id' => (string) $task->getKey(),
                        'type' => $stage,
                        'title' => $title,
                        'body' => $body,
                        'channel' => $task->channel,
                        'screen' => (string) $task->screen,
                        ...collect($task->payload ?? [])->map(fn ($value) => (string) $value)->all(),
                    ])->map(fn ($value) => (string) $value)->all(),
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'channel_id' => $task->channel,
                            'icon' => 'ic_notification_sppg',
                        ],
                    ],
                ]);
                $sent++;
                $firstMessageId ??= $response['name'] ?? null;
                $token->update(['last_seen_at' => now()]);
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
                if ($this->isInvalidToken($exception)) {
                    $token->update(['is_active' => false]);
                }
            }
        }

        $notification->update($sent > 0 ? [
            'delivery_status' => 'sent',
            'sent_at' => now(),
            'fcm_message_id' => $firstMessageId,
            'error_message' => $errors === [] ? null : implode(' | ', array_unique($errors)),
        ] : [
            'delivery_status' => 'failed',
            'failed_at' => now(),
            'error_message' => implode(' | ', array_unique($errors)) ?: 'FCM tidak menerima pesan.',
        ]);

        return $notification->refresh();
    }

    private function isInvalidToken(Throwable $exception): bool
    {
        $message = $exception->getMessage();
        if ($exception instanceof RequestException) {
            $message .= ' '.(string) $exception->response->body();
        }

        return str_contains($message, 'UNREGISTERED')
            || str_contains($message, 'registration-token-not-registered');
    }
}
