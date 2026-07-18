<?php

namespace App\Http\Controllers;

use App\Support\V3\SystemUnit;
use Illuminate\Database\Eloquent\Model;

abstract class Controller
{
    protected function authorizeSystemUnit(string $permission): void
    {
        abort_unless(auth()->user()?->is_active && app(SystemUnit::class)->get(), 403);
        abort_unless(auth()->user()?->can($permission), 403);
    }

    protected function authorizeSystemRecord(Model $record, string $permission): void
    {
        abort_unless(app(SystemUnit::class)->owns($record), 403);
        $this->authorizeSystemUnit($permission);
    }
}
