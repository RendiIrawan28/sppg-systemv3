<?php

namespace App\Policies;

use App\Models\StockReceipt;
use App\Models\User;

class StockReceiptPolicy
{
    public function viewAny(User $user): bool { return $user->can('stock.view'); }
    public function view(User $user, StockReceipt $record): bool { return $user->can('stock.view'); }
    public function create(User $user): bool { return $user->can('stock.create'); }
    public function update(User $user, StockReceipt $record): bool { return $user->can('stock.update'); }
    public function delete(User $user, StockReceipt $record): bool { return $user->can('stock.delete'); }
    public function restore(User $user, StockReceipt $record): bool { return $user->can('stock.update'); }
    public function forceDelete(User $user, StockReceipt $record): bool { return false; }
}
