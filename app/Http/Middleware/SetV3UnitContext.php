<?php

namespace App\Http\Middleware;

use App\Support\V3\UnitContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetV3UnitContext
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user(), 403);

        $unit = app(UnitContext::class)->for($request->user());

        abort_unless($unit, 403, 'Akun belum memiliki unit SPPG aktif.');

        app(UnitContext::class)->activate($request->user(), $unit);
        $request->attributes->set('v3Unit', $unit);

        return $next($request);
    }
}
