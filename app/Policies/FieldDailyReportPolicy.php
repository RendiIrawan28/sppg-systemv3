<?php

namespace App\Policies;

use App\Models\FieldDailyReport;
use App\Models\User;

class FieldDailyReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('field_daily_reports.view');
    }

    public function view(User $user, FieldDailyReport $report): bool
    {
        return $user->can('field_daily_reports.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, FieldDailyReport $report): bool
    {
        return false;
    }

    public function delete(User $user, FieldDailyReport $report): bool
    {
        return false;
    }
}
