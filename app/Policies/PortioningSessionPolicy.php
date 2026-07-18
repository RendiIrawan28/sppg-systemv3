<?php

namespace App\Policies;

use App\Models\PortioningSession;
use App\Models\User;

class PortioningSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('portioning.view');
    }

    public function view(User $user, PortioningSession $session): bool
    {
        return $user->can('portioning.view');
    }

    public function create(User $user): bool
    {
        return $user->can('portioning.create');
    }

    public function update(User $user, PortioningSession $session): bool
    {
        if ($user->can('portioning.approve')) {
            return true;
        }

        return $user->can('portioning.update') && $session->isReportEditable();
    }

    public function delete(User $user, PortioningSession $session): bool
    {
        return $user->can('portioning.delete') && $session->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
