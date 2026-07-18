<?php

namespace App\Services;

use App\Support\V3\SystemUnit;
use Illuminate\Database\Eloquent\Model;

class NutritionAccessService
{
    public function authorizeRecord(
        Model $record,
        string $permission,
    ): void {
        $user = auth()->user();

        abort_unless($user && $user->can($permission), 403);
        abort_unless(
            app(SystemUnit::class)->owns($record),
            404
        );
    }
}
