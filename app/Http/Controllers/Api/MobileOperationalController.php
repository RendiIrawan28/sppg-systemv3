<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Mobile\MobileOperationalRecordTransformer;
use App\Support\Mobile\MobileWorkspaceRegistry;
use App\Support\V3\SystemUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileOperationalController extends Controller
{
    public function modules(
        Request $request,
        MobileWorkspaceRegistry $registry,
        SystemUnit $systemUnit,
    ): JsonResponse {
        $modules = collect($registry->forUser($request->user()))
            ->map(function (array $definition, string $slug) use ($systemUnit): array {
                $model = $definition['model'];

                return [
                    'slug' => $slug,
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'permission' => $definition['permission'],
                    'record_count' => $model::query()
                        ->where('sppg_unit_id', $systemUnit->id())
                        ->count(),
                ];
            })->values();

        return response()->json(['data' => $modules]);
    }

    public function index(
        Request $request,
        string $module,
        MobileWorkspaceRegistry $registry,
        MobileOperationalRecordTransformer $transformer,
        SystemUnit $systemUnit,
    ): JsonResponse {
        $definition = $registry->authorize($request->user(), $module);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $model = $definition['model'];
        $query = $model::query()
            ->where('sppg_unit_id', $systemUnit->id())
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where($definition['number'], 'like', "%{$search}%"))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query
                ->whereDate($definition['date'], '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query
                ->whereDate($definition['date'], '<=', $date));

        $this->addSummaryCounts($query, $module);
        $records = $query->latest($definition['date'])->latest('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'data' => collect($records->items())->map(
                fn ($record): array => $transformer->summary($module, $definition, $record),
            ),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function show(
        Request $request,
        string $module,
        int $record,
        MobileWorkspaceRegistry $registry,
        MobileOperationalRecordTransformer $transformer,
        SystemUnit $systemUnit,
    ): JsonResponse {
        $definition = $registry->authorize($request->user(), $module);
        $model = $definition['model'];
        $relations = array_values(array_filter(
            array_keys($definition['relations'] ?? []),
            fn (string $relation): bool => method_exists($model, $relation),
        ));
        $query = $model::query()->where('sppg_unit_id', $systemUnit->id());
        $this->addSummaryCounts($query, $module);
        $item = $query->with($relations)->findOrFail($record);

        return response()->json([
            'data' => $transformer->detail(
                $module,
                $definition,
                $item,
                (int) $systemUnit->id(),
            ),
        ]);
    }

    private function addSummaryCounts(Builder $query, string $module): void
    {
        $relations = match ($module) {
            'gudang' => ['items'],
            'gudang-stok' => ['movements'],
            'gudang-pengambilan' => ['items'],
            'persiapan' => ['items', 'deviations'],
            'kebersihan' => ['checklistItems', 'findings'],
            default => [],
        };

        if ($relations !== []) {
            $query->withCount($relations);
        }
    }
}
