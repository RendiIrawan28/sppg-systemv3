<?php

namespace App\Livewire\V3\Notifications;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\MobileNotification;
use App\Models\User;
use App\Services\Mobile\MobilePushService;
use Livewire\Component;
use Throwable;

class Broadcast extends Component
{
    use InteractsWithV3Shell;

    public string $title = '';

    public string $message = '';

    /** @var array{recipients: int, sent: int, no_device: int, failed: int}|null */
    public ?array $lastResult = null;

    public ?string $actionMessage = null;

    public function mount(): void
    {
        abort_unless($this->allowed('notifications.manage'), 403);
    }

    public function send(MobilePushService $push): void
    {
        abort_unless($this->allowed('notifications.manage'), 403);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:500'],
        ], [], [
            'title' => 'judul',
            'message' => 'isi pesan',
        ]);

        try {
            $result = $push->broadcastToAllActiveUsers(
                sender: auth()->user(),
                unitId: $this->currentUnit()->getKey(),
                title: trim($validated['title']),
                body: trim($validated['message']),
            );

            $this->lastResult = [
                'recipients' => $result['recipients'],
                'sent' => $result['sent'],
                'no_device' => $result['no_device'],
                'failed' => $result['failed'],
            ];
            $this->actionMessage = 'Notifikasi telah diproses untuk seluruh akun aktif.';
            $this->reset('title', 'message');
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('action', 'Notifikasi belum berhasil diproses. Silakan coba kembali.');
        }
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $activeAccounts = User::query()->where('is_active', true)->count();

        $recentBroadcasts = MobileNotification::query()
            ->where('notification_type', 'admin_broadcast')
            ->latest('created_at')
            ->limit(1000)
            ->get()
            ->groupBy(fn (MobileNotification $item): string => (string) data_get($item->payload, 'broadcast_id'))
            ->filter(fn ($items, string $batchId): bool => $batchId !== '')
            ->map(function ($items): array {
                $first = $items->first();

                return [
                    'title' => $first->title,
                    'body' => $first->body,
                    'sender' => data_get($first->payload, 'sent_by_name', 'Pengguna'),
                    'created_at' => $first->created_at,
                    'recipients' => $items->count(),
                    'sent' => $items->where('delivery_status', 'sent')->count(),
                    'no_device' => $items->where('delivery_status', 'no_device')->count(),
                    'failed' => $items->whereNotIn('delivery_status', ['sent', 'no_device'])->count(),
                ];
            })
            ->sortByDesc('created_at')
            ->take(10)
            ->values();

        return view('livewire.v3.notifications.broadcast', [
            ...$this->shellData($unit),
            'activeAccounts' => $activeAccounts,
            'recentBroadcasts' => $recentBroadcasts,
        ])->layout('layouts.v3', ['title' => 'Kirim Notifikasi']);
    }
}
