<?php

use App\Enums\OperationalReportStatus;
use App\Models\User;
use App\Services\BulkOperationalReportReviewService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BulkReviewTestReport extends Model
{
    protected $table = 'bulk_review_test_reports';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function () {
    $this->originalConnection = DB::getDefaultConnection();
    config(['database.connections.bulk_review_test' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
    ]]);
    DB::purge('bulk_review_test');
    DB::setDefaultConnection('bulk_review_test');
    expect(DB::connection()->getConfig('database'))->toBe(':memory:');

    Schema::create('bulk_review_test_reports', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('sppg_unit_id');
        $table->date('work_date');
        $table->string('status');
        $table->string('report_number');
    });
});

afterEach(function () {
    DB::purge('bulk_review_test');
    DB::setDefaultConnection($this->originalConnection);
});

function bulkReviewActor(bool $allowed = true, bool $head = false): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('can')->andReturnUsing(fn ($permission) => $allowed && $permission === 'processing.approve');
    $user->shouldReceive('hasRole')->andReturn($head);

    return $user;
}

function bulkReviewScope()
{
    return BulkReviewTestReport::query()->where('sppg_unit_id', 1)->whereDate('work_date', '2026-09-26');
}

it('reviews only the eligible stage in the selected unit and date', function () {
    foreach ([
        [1, '2026-09-26', 'submitted', 'A'],
        [1, '2026-09-26', 'submitted', 'B'],
        [1, '2026-09-26', 'division_approved', 'C'],
        [1, '2026-09-25', 'submitted', 'D'],
        [2, '2026-09-26', 'submitted', 'E'],
    ] as [$unit, $date, $status, $number]) {
        BulkReviewTestReport::create([
            'sppg_unit_id' => $unit, 'work_date' => $date,
            'status' => $status, 'report_number' => $number,
        ]);
    }
    $service = app(BulkOperationalReportReviewService::class);
    $actor = bulkReviewActor();
    expect($service->pendingCount(bulkReviewScope(), $actor, 'processing.approve'))->toBe(2);

    $count = $service->review(bulkReviewScope(), $actor, 'processing.approve', function ($report): void {
        $report->update(['status' => OperationalReportStatus::DivisionApproved->value]);
    });

    expect($count)->toBe(2)
        ->and(BulkReviewTestReport::orderBy('id')->pluck('status')->all())->toBe([
            'division_approved', 'division_approved', 'division_approved', 'submitted', 'submitted',
        ]);
    expect($service->pendingCount(bulkReviewScope(), bulkReviewActor(head: true), 'processing.approve'))->toBe(3);
});

it('rolls back all reports when one fails workflow validation', function () {
    foreach (['A', 'B'] as $number) {
        BulkReviewTestReport::create([
            'sppg_unit_id' => 1, 'work_date' => '2026-09-26',
            'status' => 'submitted', 'report_number' => $number,
        ]);
    }

    expect(fn () => app(BulkOperationalReportReviewService::class)->review(
        bulkReviewScope(), bulkReviewActor(), 'processing.approve',
        function ($report): void {
            if ($report->report_number === 'B') {
                throw ValidationException::withMessages(['photo' => 'Foto hasil belum lengkap.']);
            }
            $report->update(['status' => 'division_approved']);
        },
    ))->toThrow(ValidationException::class);
    expect(BulkReviewTestReport::pluck('status')->all())->toBe(['submitted', 'submitted']);
});

it('skips a group already reviewed by the first workflow and rejects unauthorized users', function () {
    foreach (['A', 'B'] as $number) {
        BulkReviewTestReport::create([
            'sppg_unit_id' => 1, 'work_date' => '2026-09-26',
            'status' => 'submitted', 'report_number' => $number,
        ]);
    }
    $service = app(BulkOperationalReportReviewService::class);
    $calls = 0;
    $count = $service->review(bulkReviewScope(), bulkReviewActor(), 'processing.approve', function () use (&$calls): void {
        $calls++;
        bulkReviewScope()->update(['status' => 'division_approved']);
    });
    expect($count)->toBe(2)->and($calls)->toBe(1);

    expect($service->pendingCount(bulkReviewScope(), bulkReviewActor(allowed: false), 'processing.approve'))->toBe(0);
    expect(fn () => $service->review(
        bulkReviewScope(), bulkReviewActor(allowed: false), 'processing.approve', fn () => null,
    ))->toThrow(HttpException::class);
});
