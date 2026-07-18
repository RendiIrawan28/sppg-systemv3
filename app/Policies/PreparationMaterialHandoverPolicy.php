<?php

namespace App\Policies;

use App\Models\PreparationMaterialHandover;
use App\Models\User;

class PreparationMaterialHandoverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock.view') || $user->can('preparation.view');
    }

    public function view(User $user, PreparationMaterialHandover $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool { return $user->can('stock.create'); }

    public function update(User $user, PreparationMaterialHandover $record): bool
    {
        return $user->can('stock.update') || $user->can('preparation.update');
    }

    public function delete(User $user, PreparationMaterialHandover $record): bool { return $user->can('stock.delete'); }
    public function restore(User $user, PreparationMaterialHandover $record): bool { return $user->can('stock.update'); }
    public function forceDelete(User $user, PreparationMaterialHandover $record): bool { return false; }
}
