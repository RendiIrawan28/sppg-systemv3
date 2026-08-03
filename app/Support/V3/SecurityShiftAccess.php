<?php

namespace App\Support\V3;

use App\Enums\UserRole;
use App\Models\SecurityShift;
use App\Models\User;

final class SecurityShiftAccess
{
    public function canView(User $user, SecurityShift $shift): bool
    {
        if ($user->is_super_admin || ! $user->can('security.view')) {
            return $user->is_super_admin;
        }

        if (! $user->hasRole(UserRole::Satpam->value)) {
            return true;
        }

        return (int) $shift->officer_id === (int) $user->getKey();
    }
}
