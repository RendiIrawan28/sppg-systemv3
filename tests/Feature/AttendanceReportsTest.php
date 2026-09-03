<?php

use App\Livewire\V3\Attendance\Index;
use App\Models\AttendanceSession;
use App\Models\Division;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\AttendanceReportData;
use Carbon\Carbon;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\IsolatedAttendanceDatabase;

uses(IsolatedAttendanceDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-03 23:30');
    $this->unit = SppgUnit::create(['code' => 'REPORT', 'name' => 'SPPG Contoh', 'slug' => 'report', 'is_active' => true, 'head_name' => 'Kepala SPPG']);
    $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'test', 'is_active' => true, 'is_super_admin' => true]);
    $this->division = Division::create(['name' => 'Pengolahan Terbaru', 'code' => 'OLAH', 'sort_order' => 1, 'is_active' => true]);
    $this->employee = User::create(['name' => '=Nama Uji', 'email' => 'employee@example.test', 'password' => 'test', 'employee_number' => '0012345', 'is_active' => true]);
    $this->session = AttendanceSession::create(['sppg_unit_id' => $this->unit->id, 'user_id' => $this->employee->id, 'work_date' => '2026-09-03', 'division_id' => $this->division->id, 'division_name_snapshot' => 'Pengolahan Lama', 'shift_name_snapshot' => 'Shift Pagi', 'scheduled_check_in_at' => '2026-09-03 08:00', 'scheduled_check_out_at' => '2026-09-03 16:00', 'check_in_at' => '2026-09-03 08:15', 'check_out_at' => '2026-09-03 16:00', 'status' => 'present', 'source' => 'rfid', 'late_minutes' => 5, 'punctuality_status' => 'late', 'notes' => '=1+1']);
    $this->query = ['date_from' => '2026-09-03', 'date_to' => '2026-09-03'];
});

afterEach(fn () => Carbon::setTestNow());

function attendanceWorkbook($response)
{
    $path = tempnam(sys_get_temp_dir(), 'attendance-test-');
    try {
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path);
    } finally {
        unlink($path);
    }
}

it('exports literal UID and text using snapshot division names', function () {
    $response = $this->actingAs($this->admin)->get(route('v3.attendance.xlsx', $this->query))->assertOk();
    $workbook = attendanceWorkbook($response);
    expect($workbook->getSheetNames())->toBe(['Rekap Semua', 'Pengolahan Lama']);
    $sheet = $workbook->getSheet(0);
    expect($sheet->getCell('C6')->getValue())->toBe('Pengolahan Lama')
        ->and($sheet->getCell('D6')->getValue())->toBe('=Nama Uji')->and($sheet->getCell('D6')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('E6')->getValue())->toBe('0012345')->and($sheet->getCell('O6')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('J6')->getValue())->toBe('Terlambat 5 menit');
    $workbook->disconnectWorksheets();
});

it('exports one worksheet when one division is selected even after a rename', function () {
    $this->session->replicate()->fill(['work_date' => '2026-09-04', 'division_name_snapshot' => 'Pengolahan Baru', 'uuid' => null])->save();
    $response = $this->actingAs($this->admin)->get(route('v3.attendance.xlsx', [...$this->query, 'date_to' => '2026-09-04', 'division_id' => $this->division->id]))->assertOk();
    $workbook = attendanceWorkbook($response);
    expect($workbook->getSheetCount())->toBe(1)->and($workbook->getSheet(0)->getHighestRow())->toBe(7);
});

it('sanitizes duplicate worksheet names without losing division data', function () {
    $this->session->update(['division_name_snapshot' => 'A/B']);
    $second = Division::create(['code' => 'OTHER', 'name' => 'A:B', 'is_active' => true]);
    $this->session->replicate()->fill(['division_id' => $second->id, 'division_name_snapshot' => 'A:B', 'uuid' => null])->save();
    $response = $this->actingAs($this->admin)->get(route('v3.attendance.xlsx', $this->query))->assertOk();
    expect(attendanceWorkbook($response)->getSheetNames())->toBe(['Rekap Semua', 'A B', 'A B (2)']);
});

it('filters date division and employee consistently and keeps missing division last', function () {
    $this->session->replicate()->fill(['division_id' => null, 'division_name_snapshot' => null, 'uuid' => null])->save();
    $this->session->replicate()->fill(['work_date' => '2026-09-02', 'uuid' => null])->save();
    $service = app(AttendanceReportData::class);
    expect($service->sessions($this->unit->id, '2026-09-03', '2026-09-03', $this->division->id, '0012345')->count())->toBe(1)
        ->and($service->groups($service->sessions($this->unit->id, '2026-09-03', '2026-09-03'))->last()['name'])->toBe('Tanpa Divisi');
    Livewire::actingAs($this->admin)->test(Index::class)->set('filterDivisionId', (string) $this->division->id)
        ->assertSee('Pengolahan Lama')->assertSee('Terlambat 5 menit')->assertViewHas('summary', fn ($summary) => $summary['present'] === 1 && $summary['late'] === 1);
});

it('does not crash when the date filter is empty or malformed', function () {
    Livewire::actingAs($this->admin)->test(Index::class)->set('filterDate', '')
        ->assertSee('Pilih tanggal presensi yang valid.')->set('filterDate', 'not-a-date')->assertOk();
});

it('renders multipage export fixtures without querying the active database', function () {
    foreach (range(1, 45) as $i) {
        $user = User::create(['name' => 'Pegawai Contoh '.$i, 'email' => 'pdf'.$i.'@example.test', 'password' => 'test', 'employee_number' => '000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'is_active' => true]);
        $this->session->replicate()->fill(['user_id' => $user->id, 'notes' => $i % 3 === 0 ? 'Catatan uji untuk memeriksa pembungkusan teks pada laporan.' : null, 'uuid' => null])->save();
    }
    $service = app(AttendanceReportData::class);
    $sessions = $service->sessions($this->unit->id, '2026-09-03', '2026-09-03');
    $html = view('reports.attendance-report-pdf', ['unit' => $this->unit, 'from' => '2026-09-03', 'to' => '2026-09-03', 'divisionLabel' => 'Semua Divisi', 'groups' => $service->groups($sessions)])->render();
    expect($html)->toContain('REKAP PRESENSI PEGAWAI', 'Pengolahan Lama', 'Terlambat 5 menit')->not->toContain('Pengolahan Terbaru');
    $pdf = $this->actingAs($this->admin)->get(route('v3.attendance.pdf', $this->query))->assertOk();
    expect($pdf->getContent())->toStartWith('%PDF-');
    if (getenv('ATTENDANCE_QA_PDF')) {
        file_put_contents(getenv('ATTENDANCE_QA_PDF'), $pdf->getContent());
    }
});
