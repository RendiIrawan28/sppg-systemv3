<?php

use App\Enums\UserRole;
use App\Support\AccessControl;

it('allows only management roles to broadcast website notifications', function (): void {
    expect(AccessControl::permissionsForRole(UserRole::KepalaSppg->value))
        ->toContain('notifications.manage')
        ->and(AccessControl::permissionsForRole(UserRole::AdminSppg->value))
        ->toContain('notifications.manage')
        ->and(AccessControl::permissionsForRole(UserRole::Satpam->value))
        ->not->toContain('notifications.manage');
});

it('keeps the broadcast route and page protected by notification management access', function (): void {
    $routes = file_get_contents(base_path('routes/v3.php'));
    $component = file_get_contents(app_path('Livewire/V3/Notifications/Broadcast.php'));
    $view = file_get_contents(resource_path('views/livewire/v3/notifications/broadcast.blade.php'));

    expect($routes)
        ->toContain("Route::get('/notifikasi/kirim', NotificationBroadcast::class)")
        ->and($component)
        ->toContain("abort_unless(\$this->allowed('notifications.manage'), 403)")
        ->and($view)
        ->toContain('Kirim ke semua akun')
        ->toContain('Riwayat pengiriman');
});

it('creates an in-app notification and pushes it to every active account', function (): void {
    $service = file_get_contents(app_path('Services/Mobile/MobilePushService.php'));

    expect($service)
        ->toContain("->where('is_active', true)")
        ->toContain("'notification_type' => 'admin_broadcast'")
        ->toContain("'screen' => 'notifications'")
        ->toContain("'delivery_status' => 'pending'");
});
