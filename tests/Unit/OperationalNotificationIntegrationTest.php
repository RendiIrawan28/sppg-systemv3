<?php

it('stores operational notifications after commit and queues firebase delivery', function (): void {
    $service = file_get_contents(app_path('Services/Mobile/OperationalNotificationService.php'));
    $job = file_get_contents(app_path('Jobs/DeliverMobileNotification.php'));

    expect($service)
        ->toContain('DB::afterCommit')
        ->toContain('firstOrCreate')
        ->toContain('DeliverMobileNotification::dispatch')
        ->toContain("'priority' => \$priority")
        ->toContain("'reference_type' => \$referenceType")
        ->toContain("'reference_id' => (string) \$referenceId")
        ->and($job)
        ->toContain('implements ShouldQueue')
        ->toContain('deliverStoredNotification');
});

it('resolves operational recipients by active account permission and division scope', function (): void {
    $resolver = file_get_contents(app_path('Services/Mobile/NotificationRecipientResolver.php'));

    expect($resolver)
        ->toContain("->where('is_active', true)")
        ->toContain('->permission($permission)')
        ->toContain("where('division_user.sppg_unit_id', \$unitId)")
        ->toContain("where('division_user.is_active', true)");
});

it('connects the requested operational transitions without replacing existing notification features', function (): void {
    $files = collect([
        'Services/WarehouseWithdrawalService.php',
        'Services/PreparationReturnService.php',
        'Services/ProcessingReturnService.php',
        'Services/PreparationOutputService.php',
        'Services/ProcessingPortioningHandoverService.php',
        'Services/PortioningWorkflow.php',
        'Services/FieldDistributionPlanWorkflow.php',
        'Services/ActiveFieldPlanRouteService.php',
        'Services/DistributionWorkflow.php',
    ])->map(fn (string $file): string => file_get_contents(app_path($file)))->implode("\n");

    expect($files)
        ->toContain('warehouse_withdrawal_submitted')
        ->toContain("'warehouse_withdrawal_'.\$decision")
        ->toContain('preparation_return_submitted')
        ->toContain('processing_return_submitted')
        ->toContain('preparation_output_ready')
        ->toContain('preparation_output_received')
        ->toContain('processing_batch_handed_over')
        ->toContain('processing_batch_received_by_portioning')
        ->toContain('portioning_completed')
        ->toContain('distribution_scheduled')
        ->toContain('distribution_routes_updated')
        ->toContain('distribution_completed')
        ->toContain('distribution_problem');
});

it('opens operational and field plan notifications on their relevant mobile screens', function (): void {
    $navigation = file_get_contents(base_path('android/app/src/main/java/id/sppg/mobile/core/notification/NotificationNavigationStore.kt'));
    $app = file_get_contents(base_path('android/app/src/main/java/id/sppg/mobile/ui/SppgApp.kt'));
    $center = file_get_contents(base_path('android/app/src/main/java/id/sppg/mobile/ui/TaskScreens.kt'));

    expect($navigation)
        ->toContain('moduleSlug')
        ->toContain('recordId')
        ->toContain('fieldPlanId')
        ->and($app)
        ->toContain('AppScreen.OperationalDetail')
        ->toContain('AppScreen.FieldPlanDetail')
        ->and($center)
        ->toContain('notification.payload?.get("priority")')
        ->toContain('"critical" -> SppgStatusPill("Kritis"');
});

it('keeps admin broadcast and security task notification paths intact', function (): void {
    $push = file_get_contents(app_path('Services/Mobile/MobilePushService.php'));

    expect($push)
        ->toContain('broadcastToAllActiveUsers')
        ->toContain("'notification_type' => 'admin_broadcast'")
        ->toContain('notifyTask')
        ->toContain('deliverStoredNotification');
});

it('routes report approvals to division heads, the SPPG head, and the original submitter', function (): void {
    $approval = file_get_contents(app_path('Services/Mobile/OperationalApprovalNotificationService.php'));
    $workflows = file_get_contents(app_path('Services/PreparationSessionService.php'))
        .file_get_contents(app_path('Services/ProcessingWorkflow.php'))
        .file_get_contents(app_path('Services/PortioningWorkflow.php'));

    expect($approval)
        ->toContain("permission: \$module.'.approve'")
        ->toContain('UserRole::KepalaSppg->value')
        ->toContain("getAttribute('submitted_by')")
        ->and(substr_count($workflows, 'OperationalApprovalNotificationService::class'))
        ->toBeGreaterThanOrEqual(9);
});
