<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool { return $user->can('suppliers.view'); }
    public function view(User $user, Supplier $record): bool { return $user->can('suppliers.view'); }
    public function create(User $user): bool { return $user->can('suppliers.create'); }
    public function update(User $user, Supplier $record): bool { return $user->can('suppliers.update'); }
    public function delete(User $user, Supplier $record): bool { return $user->can('suppliers.delete'); }
    public function restore(User $user, Supplier $record): bool { return $user->can('suppliers.update'); }
    public function forceDelete(User $user, Supplier $record): bool { return false; }
}
