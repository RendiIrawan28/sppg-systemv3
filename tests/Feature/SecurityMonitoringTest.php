<?php

namespace Tests\Feature;

use App\Enums\SecurityShiftStatus;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\SecurityMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SecurityMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_shift_runs_for_twelve_hours_with_four_reports(): void
    {
        [$unit, $officer] = $this->baseData();
        $service = app(SecurityMonitoringService::class);
        $startedAt = Carbon::parse('2026-07-24 06:00:00');
        $shift = $service->startShift($unit, $officer, $startedAt);

        $this->assertSame('2026-07-24 18:00', $shift->scheduled_end_at->format('Y-m-d H:i'));
        $this->assertSame(1, $shift->next_report_sequence);
        $this->assertSame('2026-07-24 09:00', $shift->next_report_due_at->format('Y-m-d H:i'));

        foreach ([3, 6, 9, 12] as $index => $hour) {
            $service->submitReport(
                $shift->fresh(),
                $officer,
                [
                    'situation' => 'safe',
                    'gate_secure' => true,
                    'perimeter_secure' => true,
                    'access_activity' => null,
                    'visitor_activity' => null,
                    'notes' => 'Situasi terkendali.',
                    'photo_path' => "test/security-{$index}.jpg",
                ],
                $startedAt->copy()->addHours($hour),
            );
        }

        $shift->refresh();
        $this->assertSame(4, $shift->reports()->count());
        $this->assertSame(SecurityShiftStatus::Completed, $shift->status);
        $this->assertSame('2026-07-24 18:00', $shift->completed_at->format('Y-m-d H:i'));
    }

    public function test_report_cannot_be_submitted_before_three_hours(): void
    {
        [$unit, $officer] = $this->baseData();
        $service = app(SecurityMonitoringService::class);
        $startedAt = Carbon::parse('2026-07-24 06:00:00');
        $shift = $service->startShift($unit, $officer, $startedAt);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('baru dapat dibuat setelah tiga jam');

        $service->submitReport(
            $shift,
            $officer,
            [
                'situation' => 'safe',
                'gate_secure' => true,
                'perimeter_secure' => true,
                'photo_path' => 'test/security-early.jpg',
            ],
            $startedAt->copy()->addHours(2),
        );
    }

    public function test_missed_due_time_remains_a_reminder_without_late_status(): void
    {
        [$unit, $officer] = $this->baseData();
        $startedAt = Carbon::parse('2026-07-24 06:00:00');
        $shift = app(SecurityMonitoringService::class)->startShift($unit, $officer, $startedAt);

        $this->assertTrue($shift->isReportDue($startedAt->copy()->addHours(5)));
        $this->assertSame(1, $shift->next_report_sequence);
        $this->assertDatabaseMissing('security_reports', [
            'security_shift_id' => $shift->id,
        ]);
    }

    public function test_an_officer_cannot_start_a_second_active_shift(): void
    {
        [$unit, $officer] = $this->baseData();
        $service = app(SecurityMonitoringService::class);
        $service->startShift($unit, $officer);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('masih memiliki shift keamanan yang aktif');

        $service->startShift($unit, $officer);
    }

    /** @return array{SppgUnit, User} */
    private function baseData(): array
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-SEC', 'name' => 'SPPG Keamanan', 'slug' => 'sppg-keamanan', 'is_active' => true,
        ]);
        $officer = User::factory()->create();
        foreach (['security.view', 'security.create', 'security.update', 'security.close'] as $permission) {
            $officer->givePermissionTo(Permission::findOrCreate($permission));
        }

        return [$unit, $officer];
    }
}
