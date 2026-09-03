<?php

namespace App\Services;

use App\Models\AttendanceSession;
use Illuminate\Support\Collection;

class AttendanceReportData
{
    public function sessions(int $unitId, string $from, string $to, ?int $divisionId = null, string $search = ''): Collection
    {
        return AttendanceSession::query()->where('sppg_unit_id', $unitId)->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)
            ->when($divisionId, fn ($q) => $q->where('division_id', $divisionId))
            ->when(trim($search) !== '', fn ($q) => $q->whereHas('user', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.trim($search).'%')->orWhere('employee_number', 'like', '%'.trim($search).'%'))))
            ->with(['user.roles', 'user.divisions', 'division'])->orderBy('work_date')->orderBy('check_in_at')->orderBy('id')->get();
    }

    public function groups(Collection $sessions): Collection
    {
        return $sessions->sortBy(fn ($row) => sprintf('%010d', $row->division?->sort_order ?? PHP_INT_MAX).'|'.($row->division_name_snapshot ?? 'Tanpa Divisi'))
            ->groupBy(fn ($row) => ($row->division_id ?? 'none').'|'.($row->division_name_snapshot ?? 'Tanpa Divisi'))
            ->map(fn ($rows) => ['name' => $rows->first()->division_name_snapshot ?? 'Tanpa Divisi', 'sessions' => $rows->sortBy(fn ($row) => $row->work_date->toDateString().'|'.mb_strtolower($row->user?->name ?? '').'|'.($row->check_in_at?->format('H:i:s') ?? ''))->values()]);
    }
}
