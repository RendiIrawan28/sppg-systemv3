<?php

namespace App\Support\V3;

use App\Models\SppgUnit;
use App\Models\User;

final class UnitContext
{
    public function for(User $user): ?SppgUnit
    {
        return $user->is_active ? app(SystemUnit::class)->get() : null;
    }

    public function activate(User $user, SppgUnit $unit): void
    {
        abort_unless($user->is_active && (int) app(SystemUnit::class)->id() === (int) $unit->getKey(), 403);
    }
}
