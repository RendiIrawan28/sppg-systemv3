<?php

namespace Tests\Feature;

use App\Enums\CleaningSessionState;
use App\Enums\OperationalReportStatus;
use App\Enums\UserRole;
use App\Enums\WashingSessionState;
use App\Models\CleaningArea;
use App\Models\CleaningSession;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\WashingSession;
use App\Services\CleaningWorkflow;
use App\Services\WashingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WashingCleaningWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_washing_session_creates_initialized_cleaning_sessions(): void
    {
        [$unit, $operator] = $this->unitAndUser();
        $area = CleaningArea::query()->create([
            'sppg_unit_id' => $unit->id,
            'code' => 'AREA-DAPUR',
            'name' => 'Area Dapur',
            'category' => 'production',
            'frequency' => 'daily',
            'default_checklist' => ['Lantai bersih', 'Meja kerja tersanitasi'],
            'is_active' => true,
            'created_by' => $operator->id,
        ]);
        $washing = WashingSession::query()->create([
            'sppg_unit_id' => $unit->id,
            'washing_date' => today(),
            'state' => WashingSessionState::Completed,
            'status' => OperationalReportStatus::Draft,
            'expected_containers' => 10,
            'received_containers' => 10,
            'washed_containers' => 10,
            'clean_containers' => 10,
            'created_by' => $operator->id,
        ]);

        app(WashingWorkflow::class)->markReady($washing, $operator);

        $cleaning = CleaningSession::query()->where('cleaning_area_id', $area->id)->firstOrFail();
        $this->assertSame(CleaningSessionState::Planned, $cleaning->state);
        $this->assertSame(2, $cleaning->checklistItems()->count());
    }

    public function test_washing_and_cleaning_keep_separate_division_and_final_approvers(): void
    {
        [$unit, $operator] = $this->unitAndUser();
        $divisionHead = User::factory()->create();
        $divisionHead->assignRole(Role::findOrCreate(UserRole::KepalaDivisiPencucian->value));
        $sppgHead = User::factory()->create();
        $sppgHead->assignRole(Role::findOrCreate(UserRole::KepalaSppg->value));

        $washing = WashingSession::query()->create([
            'sppg_unit_id' => $unit->id,
            'washing_date' => today(),
            'state' => WashingSessionState::Ready,
            'status' => OperationalReportStatus::Submitted,
            'expected_containers' => 10,
            'received_containers' => 10,
            'washed_containers' => 10,
            'clean_containers' => 10,
            'created_by' => $operator->id,
        ]);
        $washingWorkflow = app(WashingWorkflow::class);
        $washingWorkflow->verify($washing, $divisionHead);
        $washingWorkflow->verify($washing->fresh(), $sppgHead);

        $washing->refresh();
        $this->assertSame(OperationalReportStatus::Verified, $washing->status);
        $this->assertSame($divisionHead->id, $washing->division_approved_by);
        $this->assertSame($sppgHead->id, $washing->verified_by);

        $area = CleaningArea::query()->create([
            'sppg_unit_id' => $unit->id,
            'code' => 'AREA-UJI',
            'name' => 'Area Uji',
            'category' => 'production',
            'frequency' => 'daily',
            'is_active' => true,
        ]);
        $cleaning = CleaningSession::query()->create([
            'sppg_unit_id' => $unit->id,
            'cleaning_area_id' => $area->id,
            'scheduled_date' => today(),
            'state' => CleaningSessionState::Ready,
            'status' => OperationalReportStatus::Submitted,
            'created_by' => $operator->id,
        ]);
        $cleaningWorkflow = app(CleaningWorkflow::class);
        $cleaningWorkflow->verify($cleaning, $divisionHead);
        $cleaningWorkflow->verify($cleaning->fresh(), $sppgHead);

        $cleaning->refresh();
        $this->assertSame(OperationalReportStatus::Verified, $cleaning->status);
        $this->assertSame($divisionHead->id, $cleaning->division_approved_by);
        $this->assertSame($sppgHead->id, $cleaning->verified_by);
    }

    public function test_revision_must_follow_the_same_approval_stage_actor(): void
    {
        [$unit, $operator] = $this->unitAndUser();
        $sppgHead = User::factory()->create();
        $sppgHead->assignRole(Role::findOrCreate(UserRole::KepalaSppg->value));
        $washing = WashingSession::query()->create([
            'sppg_unit_id' => $unit->id,
            'washing_date' => today(),
            'state' => WashingSessionState::Ready,
            'status' => OperationalReportStatus::Submitted,
            'created_by' => $operator->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Kepala Divisi terlebih dahulu');

        app(WashingWorkflow::class)->requestRevision($washing, $sppgHead, 'Mohon koreksi data.');
    }

    /** @return array{SppgUnit, User} */
    private function unitAndUser(): array
    {
        $unit = SppgUnit::query()->create([
            'code' => 'SPPG-WASH',
            'name' => 'SPPG Pencucian',
            'slug' => 'sppg-pencucian',
            'is_active' => true,
        ]);

        return [$unit, User::factory()->create()];
    }
}
