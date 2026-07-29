<?php

namespace App\Policies;

use App\Enums\WasteDivision;
use App\Models\User;
use App\Models\WasteHandoverReport;

class WasteHandoverReportPolicy
{
    public function viewAny(User $user): bool
    {
        return collect(WasteDivision::cases())
            ->contains(fn (WasteDivision $division): bool => $user->can($division->permissionPrefix().'.view'));
    }

    public function view(User $user, WasteHandoverReport $report): bool
    {
        return $user->can($report->division_type->permissionPrefix().'.view');
    }

    public function create(User $user): bool
    {
        return collect(WasteDivision::cases())
            ->contains(fn (WasteDivision $division): bool => $user->can($division->permissionPrefix().'.update'));
    }

    public function update(User $user, WasteHandoverReport $report): bool
    {
        return $report->isEditable()
            ? $user->can($report->division_type->permissionPrefix().'.update')
            : $user->can($report->division_type->permissionPrefix().'.approve');
    }

    public function delete(User $user, WasteHandoverReport $report): bool
    {
        return $report->isEditable() && $user->can($report->division_type->permissionPrefix().'.delete');
    }
}
