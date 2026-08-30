<?php

namespace App\Jobs;

use App\Models\MobileNotification;
use App\Services\Mobile\MobilePushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverMobileNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $notificationId) {}

    public function handle(MobilePushService $push): void
    {
        $notification = MobileNotification::query()->find($this->notificationId);

        if (! $notification || $notification->delivery_status === 'sent') {
            return;
        }

        $push->deliverStoredNotification($notification);
    }
}
