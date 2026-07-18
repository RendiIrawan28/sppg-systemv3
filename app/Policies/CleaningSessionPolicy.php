<?php

namespace App\Policies;

use App\Models\CleaningSession;
use App\Models\User;

class CleaningSessionPolicy
{
    public function viewAny(User $user): bool { return $user->can('cleaning.view'); }
    public function view(User $user, CleaningSession $session): bool { return $user->can('cleaning.view'); }
    public function create(User $user): bool { return $user->can('cleaning.create'); }

    public function update(User $user, CleaningSession $session): bool
    {
        if ($user->can('cleaning.approve')) {
            return true;
        }

        return $user->can('cleaning.update') && $session->isReportEditable();
    }

    public function delete(User $user, CleaningSession $session): bool
    {
        return $user->can('cleaning.delete') && $session->canBeDeleted();
    }

    public function deleteAny(User $user): bool { return false; }
}
