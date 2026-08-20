<?php

namespace App\Http\Controllers\Api;

use App\Enums\FieldDistributionPlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MobileFieldPlanResource;
use App\Models\FieldDistributionPlan;
use App\Services\FieldDistributionPlanWorkflow;
use App\Services\FieldPlanActualConfirmationService;
use App\Services\MobileFieldPlanCreationService;
use App\Services\MobileFieldPlanUpdateService;
use App\Support\V3\SystemUnit;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FieldPlanController extends Controller
{
    public function options(
        Request $request,
        SystemUnit $systemUnit,
        MobileFieldPlanCreationService $creationService,
    ): JsonResponse {
        Gate::authorize('create', FieldDistributionPlan::class);

        return response()->json([
            'data' => $creationService->options($systemUnit->id()),
            'can_create' => true,
        ]);
    }

    public function store(
        Request $request,
        SystemUnit $systemUnit,
        MobileFieldPlanCreationService $creationService,
    ): JsonResponse {
        Gate::authorize('create', FieldDistributionPlan::class);
        $data = $request->validate([
            'distribution_date' => ['required', 'date', 'after_or_equal:today'],
            'menu_cycle_day_id' => ['nullable', 'integer'],
            'confirmation_deadline_at' => ['nullable', 'date'],
            'general_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $plan = $creationService->create($systemUnit->id(), $request->user(), $data);

        return response()->json([
            'message' => 'Rencana distribusi dibuat dan data penerima berhasil dimuat.',
            'data' => new MobileFieldPlanResource($plan),
        ], 201);
    }

    public function index(Request $request, SystemUnit $systemUnit): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', FieldDistributionPlan::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(FieldDistributionPlanStatus::cases(), 'value'))],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $plans = FieldDistributionPlan::query()
            ->where('sppg_unit_id', $systemUnit->id())
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('plan_number', 'like', "%{$search}%")
                        ->orWhere('menu_name_snapshot', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('distribution_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('distribution_date', '<=', $date))
            ->orderByDesc('distribution_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return MobileFieldPlanResource::collection($plans);
    }

    public function show(FieldDistributionPlan $plan): MobileFieldPlanResource
    {
        Gate::authorize('view', $plan);

        return new MobileFieldPlanResource(
            $plan->load(['destinations.recipientGroups']),
        );
    }

    public function update(
        Request $request,
        FieldDistributionPlan $plan,
        MobileFieldPlanUpdateService $updateService,
    ): MobileFieldPlanResource {
        Gate::authorize('update', $plan);

        $data = $request->validate([
            'general_notes' => ['nullable', 'string', 'max:5000'],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.id' => ['required', 'integer', 'distinct'],
            'destinations.*.route_name' => ['nullable', 'string', 'max:255'],
            'destinations.*.sequence_order' => ['required', 'integer', 'min:1'],
            'destinations.*.planned_departure_time' => ['nullable', 'date_format:H:i'],
            'destinations.*.planned_arrival_time' => ['nullable', 'date_format:H:i'],
            'destinations.*.special_notes' => ['nullable', 'string', 'max:2000'],
            'destinations.*.change_reason' => ['nullable', 'string', 'max:1000'],
            'destinations.*.no_service_reason' => ['nullable', 'string', 'max:1000'],
            'destinations.*.recipient_groups' => ['required', 'array', 'min:1'],
            'destinations.*.recipient_groups.*.id' => ['required', 'integer'],
            'destinations.*.recipient_groups.*.confirmed_beneficiaries' => ['required', 'integer', 'min:0'],
            'destinations.*.recipient_groups.*.menu_audience' => ['nullable', 'string', 'max:100'],
            'destinations.*.recipient_groups.*.portion_size' => ['required', 'in:small,large'],
            'destinations.*.recipient_groups.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return new MobileFieldPlanResource(
            $updateService->update($plan, $request->user(), $data),
        );
    }

    public function refreshBeneficiaries(
        Request $request,
        FieldDistributionPlan $plan,
        FieldPlanActualConfirmationService $confirmationService,
    ): JsonResponse {
        Gate::authorize('update', $plan);
        $result = $confirmationService->synchronize($plan, $request->user());

        return response()->json([
            'message' => sprintf('Penerima diperbarui: %d tujuan dan %d penerima.', $result['destination_count'], $result['confirmed_beneficiaries']),
            'data' => new MobileFieldPlanResource($plan->refresh()->load('destinations.recipientGroups')),
        ]);
    }

    public function destroy(FieldDistributionPlan $plan): JsonResponse
    {
        Gate::authorize('delete', $plan);
        $cycleDay = $plan->menuCycleDay;
        $plan->delete();
        if ($cycleDay && (int) $cycleDay->field_distribution_plan_id === (int) $plan->getKey()) {
            $cycleDay->forceFill(['field_distribution_plan_id' => null])->save();
        }

        return response()->json(['message' => 'Draft rencana distribusi berhasil dihapus.']);
    }

    public function readiness(
        FieldDistributionPlan $plan,
        FieldDistributionPlanWorkflow $workflow,
    ): JsonResponse {
        Gate::authorize('update', $plan);
        $issues = $workflow->submissionIssues($plan);

        return response()->json([
            'ready' => $issues === [],
            'message' => $issues === [] ? 'Rencana siap diaktifkan.' : 'Rencana belum siap diaktifkan.',
            'issues' => $issues,
        ]);
    }

    public function activate(
        Request $request,
        FieldDistributionPlan $plan,
        FieldDistributionPlanWorkflow $workflow,
    ): JsonResponse {
        Gate::authorize('update', $plan);
        abort_unless($request->user()->can('field_planning.submit'), 403);
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $workflow->submit(
                $plan,
                $request->user(),
                filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            );
        } catch (DomainException|RuntimeException $exception) {
            throw ValidationException::withMessages(['plan' => [$exception->getMessage()]]);
        }

        $generated = $result['operational_documents'] ?? [];
        $hasDistributionRoutes = ! empty($generated['distribution_runs'] ?? []);

        return response()->json([
            'message' => $hasDistributionRoutes
                ? 'Rencana berhasil diaktifkan dan rute Distribusi telah disiapkan. Pengolahan dan Pemorsian dimulai manual oleh divisi masing-masing.'
                : 'Rencana berhasil diaktifkan tanpa rute Distribusi karena seluruh tujuan tidak menerima pelayanan.',
            'generated' => $generated,
            'data' => new MobileFieldPlanResource(
                $plan->refresh()->load('destinations.recipientGroups'),
            ),
        ]);
    }
}
