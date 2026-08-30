<?php

namespace App\Services\Mobile;

use App\Jobs\DeliverMobileNotification;
use App\Models\MobileNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class OperationalNotificationService
{
    public function __construct(private readonly NotificationRecipientResolver $recipients) {}

    public function notifyPermissionAfterCommit(
        int $unitId,
        string $permission,
        string $type,
        string $title,
        string $message,
        string $priority,
        string $module,
        string $referenceType,
        int|string $referenceId,
        string $moduleSlug,
        string $moduleLabel,
        string $eventVersion,
        ?string $divisionCode = null,
        array $payload = [],
        string $screen = 'operational',
    ): void {
        DB::afterCommit(function () use ($unitId, $permission, $type, $title, $message, $priority, $module, $referenceType, $referenceId, $moduleSlug, $moduleLabel, $eventVersion, $divisionCode, $payload, $screen): void {
            try {
                $this->storeForUsers(
                    $this->recipients->usersWithPermissionInUnit($unitId, $permission, $divisionCode),
                    $unitId,
                    $type,
                    $title,
                    $message,
                    $priority,
                    $module,
                    $referenceType,
                    $referenceId,
                    $moduleSlug,
                    $moduleLabel,
                    $eventVersion,
                    $payload,
                    $screen,
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function notifyUsersAfterCommit(
        int $unitId,
        array $userIds,
        string $type,
        string $title,
        string $message,
        string $priority,
        string $module,
        string $referenceType,
        int|string $referenceId,
        string $moduleSlug,
        string $moduleLabel,
        string $eventVersion,
        array $payload = [],
        string $screen = 'operational',
    ): void {
        DB::afterCommit(function () use ($unitId, $userIds, $type, $title, $message, $priority, $module, $referenceType, $referenceId, $moduleSlug, $moduleLabel, $eventVersion, $payload, $screen): void {
            try {
                $this->storeForUsers(
                    $this->recipients->activeUsersByIds($userIds),
                    $unitId,
                    $type,
                    $title,
                    $message,
                    $priority,
                    $module,
                    $referenceType,
                    $referenceId,
                    $moduleSlug,
                    $moduleLabel,
                    $eventVersion,
                    $payload,
                    $screen,
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function notifyRolesAfterCommit(
        int $unitId,
        array $roles,
        string $type,
        string $title,
        string $message,
        string $priority,
        string $module,
        string $referenceType,
        int|string $referenceId,
        string $moduleSlug,
        string $moduleLabel,
        string $eventVersion,
        array $payload = [],
        string $screen = 'operational',
    ): void {
        DB::afterCommit(function () use ($unitId, $roles, $type, $title, $message, $priority, $module, $referenceType, $referenceId, $moduleSlug, $moduleLabel, $eventVersion, $payload, $screen): void {
            try {
                $this->storeForUsers(
                    $this->recipients->activeUsersWithRoles($roles),
                    $unitId,
                    $type,
                    $title,
                    $message,
                    $priority,
                    $module,
                    $referenceType,
                    $referenceId,
                    $moduleSlug,
                    $moduleLabel,
                    $eventVersion,
                    $payload,
                    $screen,
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /** @param Collection<int, User> $users */
    private function storeForUsers(
        Collection $users,
        int $unitId,
        string $type,
        string $title,
        string $message,
        string $priority,
        string $module,
        string $referenceType,
        int|string $referenceId,
        string $moduleSlug,
        string $moduleLabel,
        string $eventVersion,
        array $payload,
        string $screen,
    ): void {
        $priority = in_array($priority, ['info', 'important', 'critical'], true) ? $priority : 'info';

        foreach ($users as $user) {
            $dedupeKey = hash('sha256', implode(':', [
                'operational', $type, $referenceType, $referenceId, $user->getKey(), $eventVersion,
            ]));

            $notification = MobileNotification::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'sppg_unit_id' => $unitId,
                    'user_id' => $user->getKey(),
                    'mobile_task_id' => null,
                    'notification_type' => $type,
                    'title' => $title,
                    'body' => $message,
                    'channel' => 'sppg_tasks',
                    'screen' => $screen,
                    'payload' => [
                        'priority' => $priority,
                        'module' => $module,
                        'type' => $type,
                        'reference_type' => $referenceType,
                        'reference_id' => (string) $referenceId,
                        'module_slug' => $moduleSlug,
                        'module_label' => $moduleLabel,
                        'record_id' => (string) $referenceId,
                        'action_url' => "/mobile/{$moduleSlug}/{$referenceId}",
                        ...$payload,
                    ],
                    'delivery_status' => 'pending',
                ],
            );

            if ($notification->wasRecentlyCreated || $notification->delivery_status !== 'sent') {
                try {
                    DeliverMobileNotification::dispatch($notification->getKey());
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
    }
}
