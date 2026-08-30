<?php

namespace App\Services\Mobile;

use App\Models\MobileDeviceToken;
use App\Models\MobileNotification;
use App\Models\MobileTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
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

        return $this->deliver(
            notification: $notification,
            tokens: $tokens,
            title: $title,
            body: $body,
            data: [
                'notification_id' => (string) $notification->getKey(),
                'task_id' => (string) $task->getKey(),
                'type' => $stage,
                'title' => $title,
                'body' => $body,
                'channel' => $task->channel,
                'screen' => (string) $task->screen,
                ...$this->normalizeData($task->payload ?? []),
            ],
            channel: $task->channel,
        );
    }

    public function deliverStoredNotification(MobileNotification $notification): MobileNotification
    {
        $tokens = MobileDeviceToken::query()
            ->where('user_id', $notification->user_id)
            ->active()
            ->get();

        return $this->deliver(
            notification: $notification,
            tokens: $tokens,
            title: $notification->title,
            body: $notification->body,
            data: [
                'notification_id' => (string) $notification->getKey(),
                'type' => $notification->notification_type,
                'title' => $notification->title,
                'body' => $notification->body,
                'channel' => $notification->channel,
                'screen' => (string) $notification->screen,
                ...$this->normalizeData($notification->payload ?? []),
            ],
            channel: $notification->channel,
        );
    }

    public function sendTestNotification(
        User $user,
        ?int $unitId,
        string $installationId,
    ): MobileNotification {
        $notification = MobileNotification::query()->create([
            'sppg_unit_id' => $unitId,
            'user_id' => $user->getKey(),
            'mobile_task_id' => null,
            'notification_type' => 'fcm_test',
            'title' => 'Notifikasi SPPG berhasil terhubung',
            'body' => 'Perangkat ini sudah dapat menerima notifikasi dari server Laravel.',
            'channel' => 'sppg_tasks',
            'screen' => 'notifications',
            'payload' => [
                'installation_id' => $installationId,
                'tested_at' => now()->toIso8601String(),
            ],
            'delivery_status' => 'pending',
            'dedupe_key' => hash('sha256', 'fcm-test:'.Str::uuid()),
        ]);

        $tokens = MobileDeviceToken::query()
            ->where('user_id', $user->getKey())
            ->where('installation_id', $installationId)
            ->active()
            ->get();

        return $this->deliver(
            notification: $notification,
            tokens: $tokens,
            title: $notification->title,
            body: $notification->body,
            data: [
                'notification_id' => (string) $notification->getKey(),
                'type' => 'fcm_test',
                'title' => $notification->title,
                'body' => $notification->body,
                'channel' => $notification->channel,
                'screen' => 'notifications',
            ],
            channel: $notification->channel,
        );
    }

    /**
     * @return array{batch_id: string, recipients: int, sent: int, no_device: int, failed: int}
     */
    public function broadcastToAllActiveUsers(
        User $sender,
        ?int $unitId,
        string $title,
        string $body,
    ): array {
        $batchId = (string) Str::uuid();
        $summary = [
            'batch_id' => $batchId,
            'recipients' => 0,
            'sent' => 0,
            'no_device' => 0,
            'failed' => 0,
        ];

        User::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($sender, $unitId, $title, $body, $batchId, &$summary): void {
                foreach ($users as $user) {
                    $notification = MobileNotification::query()->create([
                        'sppg_unit_id' => $unitId,
                        'user_id' => $user->getKey(),
                        'mobile_task_id' => null,
                        'notification_type' => 'admin_broadcast',
                        'title' => $title,
                        'body' => $body,
                        'channel' => 'sppg_tasks',
                        'screen' => 'notifications',
                        'payload' => [
                            'broadcast_id' => $batchId,
                            'sent_by_id' => $sender->getKey(),
                            'sent_by_name' => $sender->name,
                        ],
                        'delivery_status' => 'pending',
                        'dedupe_key' => hash('sha256', "admin-broadcast:{$batchId}:{$user->getKey()}"),
                    ]);

                    $tokens = MobileDeviceToken::query()
                        ->where('user_id', $user->getKey())
                        ->active()
                        ->get();

                    $notification = $this->deliver(
                        notification: $notification,
                        tokens: $tokens,
                        title: $title,
                        body: $body,
                        data: [
                            'notification_id' => (string) $notification->getKey(),
                            'type' => 'admin_broadcast',
                            'title' => $title,
                            'body' => $body,
                            'channel' => 'sppg_tasks',
                            'screen' => 'notifications',
                            'broadcast_id' => $batchId,
                        ],
                        channel: 'sppg_tasks',
                    );

                    $summary['recipients']++;
                    if ($notification->delivery_status === 'sent') {
                        $summary['sent']++;
                    } elseif ($notification->delivery_status === 'no_device') {
                        $summary['no_device']++;
                    } else {
                        $summary['failed']++;
                    }
                }
            });

        return $summary;
    }

    /**
     * @param  Collection<int, MobileDeviceToken>  $tokens
     * @param  array<string, string>  $data
     */
    private function deliver(
        MobileNotification $notification,
        Collection $tokens,
        string $title,
        string $body,
        array $data,
        string $channel,
    ): MobileNotification {
        if ($tokens->isEmpty()) {
            $notification->update([
                'delivery_status' => 'no_device',
                'failed_at' => now(),
                'error_message' => 'Pengguna belum mempunyai perangkat aktif.',
            ]);

            return $notification->refresh();
        }

        $configuration = $this->fcm->configurationStatus();
        if (! $configuration['configured']) {
            $notification->update([
                'delivery_status' => 'not_configured',
                'failed_at' => now(),
                'error_message' => $configuration['message'],
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
                    'data' => $this->normalizeData($data),
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'channel_id' => $channel,
                            'icon' => 'ic_notification_sppg',
                            'default_sound' => true,
                        ],
                    ],
                ]);
                $sent++;
                $firstMessageId ??= $response['name'] ?? null;
                $token->update(['last_seen_at' => now()]);
            } catch (Throwable $exception) {
                report($exception);
                $errors[] = $this->userFacingDeliveryError($exception);
                if ($this->isInvalidToken($exception)) {
                    $token->update(['is_active' => false]);
                }
            }
        }

        $notification->update($sent > 0 ? [
            'delivery_status' => 'sent',
            'sent_at' => now(),
            'failed_at' => null,
            'fcm_message_id' => $firstMessageId,
            'error_message' => $errors === [] ? null : implode(' | ', array_unique($errors)),
        ] : [
            'delivery_status' => 'failed',
            'failed_at' => now(),
            'error_message' => implode(' | ', array_unique($errors)) ?: 'FCM tidak menerima pesan.',
        ]);

        return $notification->refresh();
    }

    /** @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function normalizeData(array $data): array
    {
        return collect($data)
            ->map(function ($value): string {
                if ($value === null) {
                    return '';
                }
                if (is_bool($value)) {
                    return $value ? '1' : '0';
                }
                if (is_scalar($value)) {
                    return (string) $value;
                }

                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            })
            ->all();
    }

    private function isInvalidToken(Throwable $exception): bool
    {
        $message = $exception->getMessage();
        if ($exception instanceof RequestException) {
            $message .= ' '.(string) $exception->response->body();
        }

        return str_contains($message, 'UNREGISTERED')
            || str_contains($message, 'registration-token-not-registered')
            || str_contains($message, 'SENDER_ID_MISMATCH');
    }

    private function userFacingDeliveryError(Throwable $exception): string
    {
        $message = $exception->getMessage();
        if ($exception instanceof RequestException) {
            $message .= ' '.(string) $exception->response->body();
        }

        return match (true) {
            str_contains($message, 'cloudmessaging.messages.create') => 'Layanan notifikasi belum memiliki izin mengirim pesan. Hubungi administrator sistem.',
            str_contains($message, 'UNREGISTERED'),
            str_contains($message, 'registration-token-not-registered') => 'Token perangkat sudah tidak berlaku. Masuk ulang ke aplikasi untuk mendaftarkan perangkat.',
            str_contains($message, 'SENDER_ID_MISMATCH') => 'Konfigurasi project notifikasi pada aplikasi dan server tidak sesuai.',
            default => 'Pengiriman notifikasi belum berhasil. Silakan coba kembali atau hubungi administrator sistem.',
        };
    }
}
