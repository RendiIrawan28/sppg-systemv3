<?php

use App\Jobs\DeliverMobileNotification;
use App\Models\MobileNotification;
use App\Models\User;
use App\Services\Mobile\NotificationRecipientResolver;
use App\Services\Mobile\OperationalNotificationService;
use App\Services\Mobile\OperationalReportTransitionNotifier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->originalConnection = DB::getDefaultConnection();
    config(['database.connections.report_notification_test' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
    ]]);
    DB::purge('report_notification_test');
    DB::setDefaultConnection('report_notification_test');
    expect(DB::connection()->getConfig('database'))->toBe(':memory:');

    Schema::create('mobile_notifications', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid');
        $table->unsignedBigInteger('sppg_unit_id');
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('mobile_task_id')->nullable();
        $table->string('notification_type');
        $table->string('title');
        $table->text('body');
        $table->string('channel');
        $table->string('screen');
        $table->json('payload')->nullable();
        $table->string('delivery_status');
        $table->string('dedupe_key')->unique();
        $table->timestamps();
    });
    Bus::fake();
});

afterEach(function (): void {
    DB::purge('report_notification_test');
    DB::setDefaultConnection($this->originalConnection);
});

function transitionDeliveryRecord(): Model
{
    $record = new class extends Model
    {
        protected $guarded = [];
    };
    $record->forceFill([
        'id' => 15,
        'sppg_unit_id' => 1,
        'submitted_by' => 8,
        'updated_at' => now(),
    ]);

    return $record;
}

it('stores and queues a report notification only after the report transaction commits', function (): void {
    $approver = new User;
    $approver->forceFill(['id' => 9]);
    $resolver = Mockery::mock(NotificationRecipientResolver::class);
    $resolver->shouldReceive('usersWithPermissionInUnit')->once()
        ->with(1, 'distribution.approve', 'distribusi')
        ->andReturn(new EloquentCollection([$approver]));
    $notifier = new OperationalReportTransitionNotifier(new OperationalNotificationService($resolver));

    DB::transaction(function () use ($notifier): void {
        $notifier->submitted(
            collect([transitionDeliveryRecord()]), 'distribution', 'Distribusi', 'Laporan Distribusi 28-09-2026',
            'distribution.approve', 'distribusi', 'distribusi', openList: true,
        );
        expect(MobileNotification::query()->count())->toBe(0);
    });

    $notification = MobileNotification::query()->firstOrFail();
    expect($notification->user_id)->toBe(9)
        ->and($notification->notification_type)->toBe('distribution_report_submitted')
        ->and($notification->payload['module_slug'])->toBe('distribusi')
        ->and($notification->payload['record_id'])->toBe('');
    Bus::assertDispatched(DeliverMobileNotification::class, 1);
});

it('does not create a notification when the report transaction is rolled back', function (): void {
    $resolver = Mockery::mock(NotificationRecipientResolver::class);
    $resolver->shouldNotReceive('usersWithPermissionInUnit');
    $notifier = new OperationalReportTransitionNotifier(new OperationalNotificationService($resolver));

    try {
        DB::transaction(function () use ($notifier): void {
            $notifier->submitted(
                collect([transitionDeliveryRecord()]), 'distribution', 'Distribusi', 'Laporan Distribusi 28-09-2026',
                'distribution.approve', 'distribusi', 'distribusi', openList: true,
            );
            throw new RuntimeException('Rollback pengujian');
        });
    } catch (RuntimeException) {
        // The report workflow rejected the transition.
    }

    expect(MobileNotification::query()->count())->toBe(0);
    Bus::assertNotDispatched(DeliverMobileNotification::class);
});
