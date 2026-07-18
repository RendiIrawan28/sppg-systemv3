<?php

namespace App\Http\Controllers\V3;

use App\Http\Controllers\Controller;
use App\Support\V3\UnitContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EntryController extends Controller
{
    public function __invoke(Request $request, UnitContext $context): RedirectResponse
    {
        $unit = $context->for($request->user());

        abort_unless($unit, 403, 'Akun belum memiliki akses ke unit SPPG aktif.');

        return redirect()->route('v3.dashboard');
    }
}
