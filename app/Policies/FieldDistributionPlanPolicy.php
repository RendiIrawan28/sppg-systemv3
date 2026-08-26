<?php

namespace App\Policies;

use App\Models\FieldDistributionPlan;
use App\Models\User;
use App\Support\V3\SystemUnit;

class FieldDistributionPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('field_planning.view');
    }

    public function view(User $user, FieldDistributionPlan $plan): bool
    {
        return $user->can('field_planning.view')
            && app(SystemUnit::class)->owns($plan);
    }

    public function create(User $user): bool
    {
        return $user->can('field_planning.create');
    }

    public function update(User $user, FieldDistributionPlan $plan): bool
    {
        return $user->can('field_planning.update')
            && app(SystemUnit::class)->owns($plan)
            && $plan->isEditable();
    }

    public function reviseRoutes(User $user, FieldDistributionPlan $plan): bool
    {
        return $user->can('field_planning.update')
            && app(SystemUnit::class)->owns($plan)
            && $plan->canReviseActiveRoutes();
    }

    public function delete(User $user, FieldDistributionPlan $plan): bool
    {
        return $user->can('field_planning.delete')
            && app(SystemUnit::class)->owns($plan)
            && $plan->canBeDeleted();
    }
}
