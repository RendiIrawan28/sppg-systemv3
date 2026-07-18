<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

class StockMovementPolicy
{
    public function viewAny(User $user): bool { return $user->can('stock.view'); }
    public function view(User $user, StockMovement $record): bool { return $user->can('stock.view'); }
    public function create(User $user): bool { return false; }
    public function update(User $user, StockMovement $record): bool { return false; }
    public function delete(User $user, StockMovement $record): bool { return false; }
    public function restore(User $user, StockMovement $record): bool { return false; }
    public function forceDelete(User $user, StockMovement $record): bool { return false; }
}
