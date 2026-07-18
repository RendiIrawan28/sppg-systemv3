<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WashingSession;

class WashingSessionPolicy
{
    public function viewAny(User $user): bool { return $user->can('washing.view'); }
    public function view(User $user, WashingSession $session): bool { return $user->can('washing.view'); }
    public function create(User $user): bool { return $user->can('washing.create'); }

    public function update(User $user, WashingSession $session): bool
    {
        if ($user->can('washing.approve')) {
            return true;
        }
        return $user->can('washing.update') && $session->isReportEditable();
    }

    public function delete(User $user, WashingSession $session): bool
    {
        return $user->can('washing.delete') && $session->canBeDeleted();
    }

    public function deleteAny(User $user): bool { return false; }
}
