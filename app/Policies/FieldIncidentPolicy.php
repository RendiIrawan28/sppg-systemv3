<?php

namespace App\Policies;

use App\Models\FieldIncident;
use App\Models\User;

class FieldIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('field_incidents.view');
    }

    public function view(User $user, FieldIncident $incident): bool
    {
        return $user->can('field_incidents.view');
    }

    public function create(User $user): bool
    {
        return $user->can('field_incidents.create');
    }

    public function update(User $user, FieldIncident $incident): bool
    {
        return $user->can('field_incidents.update');
    }

    public function delete(User $user, FieldIncident $incident): bool
    {
        return $user->can('field_incidents.update');
    }
}
