<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WasteHandoverReport;

class WasteHandoverReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('preparation.view');
    }

    public function view(User $user, WasteHandoverReport $report): bool
    {
        return $user->can('preparation.view');
    }

    public function create(User $user): bool
    {
        return $user->can('preparation.create');
    }

    public function update(User $user, WasteHandoverReport $report): bool
    {
        if ($report->isEditable()) {
            return $user->can('preparation.update');
        }

        return $user->can('preparation.approve');
    }

    public function delete(User $user, WasteHandoverReport $report): bool
    {
        return $user->can('preparation.delete') && $report->isEditable();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, WasteHandoverReport $report): bool
    {
        return false;
    }

    public function forceDelete(User $user, WasteHandoverReport $report): bool
    {
        return false;
    }
}
