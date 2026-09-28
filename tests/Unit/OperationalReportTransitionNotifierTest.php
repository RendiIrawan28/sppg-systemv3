<?php

use App\Enums\OperationalReportStatus;
use App\Services\Mobile\OperationalNotificationService;
use App\Services\Mobile\OperationalReportTransitionNotifier;
use Illuminate\Database\Eloquent\Model;

function transitionNotificationRecord(int $id, int $submitterId): Model
{
    $record = new class extends Model
    {
        protected $guarded = [];
    };
    $record->forceFill([
        'id' => $id,
        'sppg_unit_id' => 1,
        'submitted_by' => $submitterId,
        'updated_at' => now(),
    ]);

    return $record;
}

it('sends one submission notice for an entire group and opens its module list', function (): void {
    $notifications = Mockery::mock(OperationalNotificationService::class);
    $notifications->shouldReceive('notifyPermissionAfterCommit')->once()
        ->withArgs(function (...$args): bool {
            return $args[0] === 1
                && $args[1] === 'distribution.approve'
                && $args[2] === 'distribution_report_submitted'
                && $args[9] === 'distribusi'
                && $args[12] === 'distribusi'
                && $args[13] === ['record_id' => ''];
        });

    (new OperationalReportTransitionNotifier($notifications))->submitted(
        collect([transitionNotificationRecord(10, 5), transitionNotificationRecord(11, 5)]),
        'distribution', 'Distribusi', 'Laporan Distribusi 28-09-2026 (2 rute)',
        'distribution.approve', 'distribusi', 'distribusi', openList: true,
    );
});

it('sends division approval to the SPPG head and final decisions to every distinct submitter', function (): void {
    $records = collect([
        transitionNotificationRecord(10, 5),
        transitionNotificationRecord(11, 6),
        transitionNotificationRecord(12, 5),
    ]);
    $notifications = Mockery::mock(OperationalNotificationService::class);
    $notifications->shouldReceive('notifyRolesAfterCommit')->once()
        ->withArgs(fn (...$args): bool => $args[1] === ['kepala_sppg'] && $args[2] === 'washing_report_division_approved');
    $notifications->shouldReceive('notifyUsersAfterCommit')->twice()
        ->withArgs(fn (...$args): bool => $args[1] === [5, 6]
            && in_array($args[2], ['washing_report_approved', 'washing_report_revision_required'], true));

    $notifier = new OperationalReportTransitionNotifier($notifications);
    $notifier->reviewed($records, OperationalReportStatus::DivisionApproved, 'washing', 'Pencucian', 'Laporan Pencucian', 'pencucian');
    $notifier->reviewed($records, OperationalReportStatus::Verified, 'washing', 'Pencucian', 'Laporan Pencucian', 'pencucian');
    $notifier->revisionRequired($records, 'washing', 'Pencucian', 'Laporan Pencucian', 'pencucian');
});

it('connects each previously missing report workflow at submission review and revision', function (): void {
    foreach ([
        'DistributionWorkflow.php', 'WashingWorkflow.php', 'CleaningWorkflow.php',
        'FieldDailyReportWorkflow.php', 'WasteHandoverWorkflow.php',
    ] as $file) {
        $source = file_get_contents(app_path('Services/'.$file));
        expect($source)
            ->toContain('OperationalReportTransitionNotifier::class')->toContain('->submitted(')
            ->toContain('->revisionRequired(');
        expect($source)->toContain($file === 'FieldDailyReportWorkflow.php' ? '->approved(' : '->reviewed(');
    }
});
