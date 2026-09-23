<?php

namespace App\Http\Controllers;

use App\Models\CleaningSession;
use App\Models\WashingSession;
use App\Support\V3\UnitContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class MonitoringPhotoController extends Controller
{
    public function __invoke(Request $request, string $module, int $record, string $kind, int $item)
    {
        $user = $request->user();
        abort_unless($user && ($user->is_super_admin || $user->can('monitoring_operasional.view')), 403);
        $unit = app(UnitContext::class)->for($user);
        abort_unless($unit, 403);
        $model = match ($module) { 'washing' => WashingSession::class, 'cleaning' => CleaningSession::class, default => abort(404) };
        $session = $model::query()->where('sppg_unit_id', $unit->id)->findOrFail($record);
        $relation = match ($kind) {
            'documentation' => 'documentations', 'waste' => 'wasteRecords',
            'finding' => $module === 'washing' ? 'deviations' : 'findings', default => abort(404),
        };
        $photo = $session->{$relation}()->findOrFail($item);
        $path = $photo->photo_path;
        abort_unless(is_string($path) && $path !== '' && ! str_contains($path, '..') && ! str_starts_with($path, '/') && ! str_contains($path, ':'), 404);
        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
