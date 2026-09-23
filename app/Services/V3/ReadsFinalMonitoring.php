<?php

namespace App\Services\V3;

use App\Models\AttendanceSession;
use App\Models\AttendanceWorkSchedule;
use App\Models\AttendanceWorkScheduleAssignment;
use App\Models\CleaningSession;
use App\Models\FieldDistributionPlan;
use App\Models\FieldDistributionPlanDestination;
use App\Models\User;
use App\Models\WashingSession;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/** Presentation queries only. Never invoke the operational initializers/workflows here. */
trait ReadsFinalMonitoring
{
    private function finalQuery(int $unitId, string $date, string $tab): Builder
    {
        [$model, $column] = match ($tab) {
            'washing' => [WashingSession::class, 'washing_date'],
            'cleaning' => [CleaningSession::class, 'scheduled_date'],
            'field-assistant' => [FieldDistributionPlan::class, 'distribution_date'],
            'attendance' => [AttendanceSession::class, 'work_date'],
        };

        return $model::query()->where('sppg_unit_id', $unitId)->whereDate($column, $date);
    }

    public function finalSummary(int $unitId, string $date, string $tab): array
    {
        $query = $this->finalQuery($unitId, $date, $tab);
        $card = fn ($label, $value, $detail = '') => compact('label', 'value', 'detail');
        if (in_array($tab, ['washing', 'cleaning'], true)) {
            $states = (clone $query)->selectRaw('state, COUNT(*) as total')->groupBy('state')->get();
            $statusText = $states->map(fn ($row) => ($row->state?->label() ?? 'Status belum diisi').': '.$row->total)->implode(' · ');
            $reports = (clone $query)->selectRaw('status, COUNT(*) as total')->groupBy('status')->get();
            $cards = [
                $card('Laporan', (clone $query)->count(), $statusText ?: 'Belum ada laporan'),
                $card('Disetujui Kepala SPPG', (clone $query)->where('status', 'verified')->count(), $reports->map(fn ($row) => ($row->status?->label() ?? 'Belum diisi').': '.$row->total)->implode(' · ')),
            ];
            if ($tab === 'washing') {
                foreach (['received_containers' => 'Ompreng diterima', 'washed_containers' => 'Dicuci', 'clean_containers' => 'Hasil bersih'] as $column => $label) {
                    $values = (clone $query)->selectRaw("SUM($column) as value, COUNT($column) as filled_count")->first();
                    $cards[] = $card($label, $values->filled_count ? $values->value : 'Belum diisi', 'pcs · jumlah per sesi; tahap tidak dijumlahkan bersama');
                }
            } else {
                $cards[] = $card('Area dilaporkan', (clone $query)->distinct()->count('cleaning_area_id'), 'Area unik pada tanggal ini');
            }

            $wasteModel = $tab === 'washing' ? \App\Models\WashingWasteRecord::class : \App\Models\CleaningWasteRecord::class;
            $parent = $tab === 'washing' ? 'washingSession' : 'cleaningSession';
            $dateColumn = $tab === 'washing' ? 'washing_date' : 'scheduled_date';
            $waste = $wasteModel::query()->whereHas($parent, fn ($q) => $q->where('sppg_unit_id', $unitId)->whereDate($dateColumn, $date))
                ->selectRaw('unit, SUM(quantity) as quantity_total, COUNT(*) as record_count')->groupBy('unit')->get();
            $cards[] = $card('Catatan limbah', (int) $waste->sum('record_count'), $waste->isEmpty() ? 'Belum ada catatan limbah' : $waste->map(fn ($row) => ($row->quantity_total === null ? 'Belum diisi' : $row->quantity_total).' '.($row->unit ?: 'satuan belum diisi'))->implode(' · '));

            return $cards;
        }
        if ($tab === 'field-assistant') {
            $destinations = FieldDistributionPlanDestination::query()->whereHas('plan', fn ($q) => $q->where('sppg_unit_id', $unitId)->whereDate('distribution_date', $date));
            $confirmed = (clone $destinations)->whereIn('confirmation_status', ['confirmed', 'changed']);
            $statuses = (clone $destinations)->selectRaw('confirmation_status, COUNT(*) as total')->groupBy('confirmation_status')->get();

            return [
                $card('Rencana', (clone $query)->count(), 'Termasuk rencana dibatalkan, dengan status aslinya'),
                $card('Tujuan / kunjungan', (clone $destinations)->count(), $statuses->map(fn ($row) => $this->confirmationLabel($row->confirmation_status).': '.$row->total)->implode(' · ')),
                $card('Penerima terkonfirmasi', (clone $confirmed)->sum('confirmed_beneficiaries'), 'Hanya tujuan berstatus dikonfirmasi / berubah'),
                $card('Porsi kecil rencana', (clone $destinations)->sum('small_portions')),
                $card('Porsi besar rencana', (clone $destinations)->sum('large_portions')),
            ];
        }

        $roster = $this->monitoringRoster($unitId, $date);
        $entered = (clone $query)->whereNotNull('check_in_at');
        $recordedIds = (clone $query)->distinct()->pluck('user_id')->all();

        return [
            $card('Pegawai terjadwal', count($roster), 'Mengikuti jadwal efektif dan keaktifan master saat ini; histori keaktifan tidak tersedia'),
            $card('Sudah masuk', (clone $entered)->distinct()->count('user_id'), 'Pegawai unik, termasuk yang tidak memiliki jadwal'),
            $card('Sudah keluar', (clone $entered)->whereNotNull('check_out_at')->distinct()->count('user_id'), 'Pegawai unik dengan sesi tertutup; dapat memiliki sesi lain yang masih terbuka'),
            $card('Masih bekerja', (clone $entered)->whereNull('check_out_at')->distinct()->count('user_id'), 'Pegawai unik dengan sesi terbuka'),
            $card('Belum presensi', count(array_diff($roster, $recordedIds)), 'Pegawai terjadwal tanpa catatan; bukan alpa. Izin/sakit yang tercatat tidak dihitung'),
            $card('Sesi / catatan', (clone $query)->count(), 'Seluruh sesi pada tanggal kerja, termasuk lintas tengah malam'),
        ];
    }

    /** Batch equivalent of the existing schedule resolver; no per-user queries or writes. */
    private function monitoringRoster(int $unitId, string $date): array
    {
        $day = Carbon::parse($date, config('app.timezone'));
        $members = User::query()->where('is_active', true)
            ->whereHas('divisions', fn ($q) => $q->where('division_user.sppg_unit_id', $unitId)->where('division_user.is_active', true)->where('divisions.is_active', true))
            ->with(['divisions' => fn ($q) => $q->where('division_user.sppg_unit_id', $unitId)->where('division_user.is_active', true)->where('divisions.is_active', true)->orderByPivot('is_primary', 'desc')->orderBy('divisions.sort_order')->orderBy('divisions.id')])
            ->get(['id']);
        $schedules = AttendanceWorkSchedule::query()->where('sppg_unit_id', $unitId)->where('is_active', true)->orderBy('id')->get()->keyBy('id');
        $assignments = AttendanceWorkScheduleAssignment::query()->where('sppg_unit_id', $unitId)->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->orderByDesc('effective_from')->orderByDesc('id')->get()->groupBy('user_id');

        return $members->filter(function ($user) use ($assignments, $schedules, $day) {
            $assignment = $assignments->get($user->id)?->first();
            if ($assignment) {
                $schedule = $schedules->get($assignment->attendance_work_schedule_id);

                return $schedule && $user->divisions->contains('id', $schedule->division_id) && $schedule->appliesToDate($day);
            }

            return $schedules->contains(fn ($schedule) => $schedule->is_default && $schedule->division_id === $user->divisions->first()?->id && $schedule->appliesToDate($day));
        })->pluck('id')->all();
    }

    public function finalModule(int $unitId, string $date, string $tab): array
    {
        $query = $this->finalQuery($unitId, $date, $tab);
        $relations = match ($tab) {
            'washing' => ['petugas', 'checklistItems.checker', 'wasteRecords', 'documentations', 'measurements', 'deviations', 'chemicalUsages'],
            'cleaning' => ['petugas', 'cleaningArea', 'checklistItems.checker', 'wasteRecords', 'documentations', 'findings', 'chemicalUsages'],
            'field-assistant' => ['destinations', 'distributionRuns.stops'],
            'attendance' => ['user:id,name'],
        };
        $records = $query->with($relations)->orderByDesc('id')->paginate(15, ['*'], 'monitoringPage');
        $rows = $records->getCollection()->map(fn ($record) => match ($tab) {
            'washing', 'cleaning' => $this->hygieneRow($record, $tab),
            'field-assistant' => $this->fieldRow($record),
            'attendance' => $this->attendanceRow($record),
        })->all();

        return ['cards' => $this->finalSummary($unitId, $date, $tab), 'rows' => $rows, 'pagination' => $records];
    }

    private function hygieneRow($session, string $tab): array
    {
        $washing = $tab === 'washing';
        $fields = [
            'Tanggal' => ($washing ? $session->washing_date : $session->scheduled_date)?->format('d-m-Y'),
            'Area' => $washing ? $session->washing_area : $session->cleaningArea?->name,
            'Petugas' => $session->petugas_name_snapshot ?: $session->petugas?->name,
            'Mulai' => $session->started_at?->format('d-m-Y H:i'),
            'Selesai' => $session->completed_at?->format('d-m-Y H:i'),
            'Proses' => $session->state?->label(), 'Laporan' => $session->status?->label(),
            'Catatan' => $session->notes,
        ];
        if ($washing) {
            foreach (['received_containers' => 'Diterima', 'washed_containers' => 'Dicuci', 'clean_containers' => 'Bersih', 'damaged_containers' => 'Rusak', 'rejected_containers' => 'Ditolak', 'missing_containers' => 'Hilang tercatat'] as $key => $label) {
                $fields[$label.' (pcs)'] = $session->{$key};
            }
        } else {
            $fields['Shift'] = $session->shift;
            $fields['Kondisi awal'] = $session->before_condition;
            $fields['Kondisi akhir'] = $session->after_condition;
        }
        $checklist = $session->checklistItems->map(fn ($item) => [
            'Pemeriksaan' => $item->item_name, 'Kategori' => $item->category,
            'Wajib' => $item->is_mandatory ? 'Ya' : 'Tidak',
            'Hasil' => $washing
                ? ($item->checked_at === null ? 'Belum diperiksa' : ($item->is_passed === null ? 'Belum diisi' : ($item->is_passed ? 'Terpenuhi' : 'Tidak terpenuhi')))
                : match ($item->result) { 'pass' => 'Terpenuhi', 'fail' => 'Tidak terpenuhi', 'na' => 'Tidak berlaku', null, '', 'pending' => 'Belum diisi', default => $item->result },
            'Catatan' => $item->notes, 'Petugas' => $item->checker?->name, 'Waktu' => $item->checked_at?->format('d-m-Y H:i'),
        ])->all();
        $waste = $session->wasteRecords->map(fn ($item) => [
            'Jenis' => $item->waste_type, 'Jumlah' => $item->quantity, 'Satuan' => $item->unit,
            'Penanganan' => $item->disposal_method, 'Diserahkan kepada' => $item->handed_over_to,
            'Catatan' => $item->notes,
            'Foto' => $this->finalPhoto($tab, $session->id, 'waste', $item->id, $item->photo_path, $item->waste_type),
        ])->all();
        $photos = $session->documentations->map(fn ($item) => [
            'Fase' => $item->phase, 'Keterangan' => $item->caption, 'Waktu' => $item->captured_at?->format('d-m-Y H:i'),
            'Foto' => $this->finalPhoto($tab, $session->id, 'documentation', $item->id, $item->photo_path, $session->session_number.' · '.$item->caption),
        ])->all();
        $sections = ['Checklist' => $checklist, 'Limbah' => $waste, 'Dokumentasi' => $photos];
        $sections['Bahan pembersih'] = $session->chemicalUsages->map(fn ($item) => [
            'Bahan' => $item->chemical_name, 'Jumlah' => $item->quantity, 'Satuan' => $item->unit,
            'Waktu pemakaian' => $item->used_at?->format('d-m-Y H:i'), 'Catatan' => $item->notes,
        ])->all();
        if ($washing) {
            $sections['Pengukuran'] = $session->measurements->map(fn ($item) => [
                'Fase' => $item->phase, 'Waktu' => $item->measured_at?->format('d-m-Y H:i'),
                'Suhu °C' => $item->water_temperature_celsius, 'pH' => $item->water_ph,
                'Sanitizer ppm' => $item->sanitizer_concentration_ppm, 'Tindakan' => $item->corrective_action, 'Catatan' => $item->notes,
            ])->all();
        }
        $sections['Temuan'] = ($washing ? $session->deviations : $session->findings)->map(fn ($item) => [
            'Temuan' => $item->description, 'Tingkat' => $item->severity?->label(), 'Status' => $item->status?->label(),
            'Tindakan' => $washing ? $item->immediate_action : $item->corrective_action,
            'Catatan' => $item->notes, 'Foto' => $this->finalPhoto($tab, $session->id, 'finding', $item->id, $item->photo_path, $item->description),
        ])->all();

        return ['id' => $session->id, 'title' => $session->session_number, 'fields' => $fields, 'sections' => $sections];
    }

    private function fieldRow(FieldDistributionPlan $plan): array
    {
        return [
            'id' => $plan->id, 'title' => $plan->plan_number,
            'fields' => ['Tanggal distribusi' => $plan->distribution_date?->format('d-m-Y'), 'Tanggal pelayanan' => $plan->service_date?->format('d-m-Y'), 'Status rencana' => $plan->status?->label(), 'Catatan' => $plan->general_notes],
            'sections' => [
                'Tujuan / kunjungan' => $plan->destinations->map(fn ($destination) => [
                    'Tujuan' => $destination->destination_name_snapshot, 'Jenis' => $destination->destination_type,
                    'Penerima rencana' => $destination->registered_beneficiaries,
                    'Penerima terkonfirmasi' => in_array($destination->confirmation_status, ['confirmed', 'changed'], true) ? $destination->confirmed_beneficiaries : 'Belum dikonfirmasi',
                    'Porsi kecil rencana' => $destination->small_portions, 'Porsi besar rencana' => $destination->large_portions, 'Total porsi rencana' => $destination->total_portions,
                    'Rute' => $destination->route_name ?: 'Belum ditentukan',
                    'Jadwal tiba' => $destination->planned_arrival_at?->format('d-m-Y H:i'),
                    'Konfirmasi' => $this->confirmationLabel($destination->confirmation_status), 'Petugas konfirmasi' => $destination->confirmed_by_name,
                    'Waktu konfirmasi' => $destination->confirmed_at?->format('d-m-Y H:i'), 'Alasan perubahan' => $destination->change_reason, 'Catatan' => $destination->special_notes,
                ])->all(),
                'Rute terkait' => $plan->distributionRuns->map(fn ($run) => ['Rute' => $run->route_name, 'Status' => $run->state?->label(), 'Berangkat aktual' => $run->actual_departure_at?->format('d-m-Y H:i'), 'Kembali aktual' => $run->returned_at?->format('d-m-Y H:i')])->all(),
                'Kedatangan aktual per tujuan' => $plan->distributionRuns->flatMap(fn ($run) => $run->stops->map(fn ($stop) => ['Rute' => $run->route_name, 'Tujuan' => $stop->destination_name, 'Tiba aktual' => $stop->arrived_at?->format('d-m-Y H:i'), 'Status' => $stop->status?->label()]))->all(),
            ],
        ];
    }

    private function attendanceRow(AttendanceSession $session): array
    {
        return [
            'id' => $session->id, 'title' => $session->user?->name ?: 'Pegawai tidak tersedia',
            'fields' => [
                'Tanggal kerja' => $session->work_date?->format('d-m-Y'), 'Divisi' => $session->division_name_snapshot,
                'Shift' => $session->shift_name_snapshot, 'Masuk' => $session->check_in_at?->format('d-m-Y H:i'),
                'Keluar' => $session->check_out_at?->format('d-m-Y H:i'),
                'Durasi' => $session->check_in_at && $session->check_out_at
                    ? ($session->check_out_at->lt($session->check_in_at) ? 'Waktu perlu diperiksa pada modul sumber' : $session->durationMinutes().' menit')
                    : ($session->check_in_at ? 'Belum selesai' : 'Tidak berlaku'),
                'Status' => $session->statusLabel(),
                'Sesi' => $session->check_in_at ? ($session->check_out_at ? 'Sudah keluar' : 'Masih bekerja') : 'Tanpa tap masuk',
            ], 'sections' => [],
        ];
    }

    private function confirmationLabel(?string $status): string
    {
        return match ($status) {
            'confirmed' => 'Dikonfirmasi', 'changed' => 'Dikonfirmasi dengan perubahan',
            'pending', null, '' => 'Belum dikonfirmasi', default => $status,
        };
    }

    private function finalPhoto(string $module, int $record, string $kind, int $item, ?string $path, ?string $title): ?array
    {
        return filled($path) ? ['url' => route('v3.monitoring.photo', compact('module', 'record', 'kind', 'item')), 'title' => $title ?: 'Dokumentasi'] : null;
    }
}
