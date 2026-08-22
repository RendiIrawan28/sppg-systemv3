<?php

namespace App\Services;

use App\Models\MeasurementUnit;
use App\Models\NutritionRequirementPlan;
use App\Models\ProcurementRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\V3\SystemUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProcurementRequestService
{
    public function createFromNutritionRequirement(NutritionRequirementPlan $plan): ProcurementRequest
    {
        $user = $this->authenticatedUser();
        $this->ensurePermission($user, 'procurement.create');
        $this->ensureUnitAccess($user, $plan->sppg_unit_id);

        return $this->createOrSynchronizeDraft($plan, $user);
    }

    public function createOrSynchronizeDraft(NutritionRequirementPlan $plan, User $actor): ProcurementRequest
    {
        $this->ensureUnitAccess($actor, $plan->sppg_unit_id);
        app(MenuServiceCalendarService::class)->assertOperationalDate(
            (int) $plan->sppg_unit_id,
            $plan->requirement_date,
            'Pengadaan pangan',
        );
        $plan->loadMissing(['items.ingredient.measurementUnit', 'fieldDistributionPlan']);

        if (! $plan->items()->exists()) {
            throw new RuntimeException('Permintaan pembelian hanya dapat dibuat setelah kebutuhan bahan dihitung.');
        }

        if ($plan->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Daftar kebutuhan bahan masih kosong.',
            ]);
        }

        return DB::transaction(function () use ($plan, $actor): ProcurementRequest {
            $existing = ProcurementRequest::query()
                ->where('nutrition_requirement_plan_id', $plan->id)
                ->first();

            if ($existing) {
                if ($existing->isEditable()) {
                    $foodWarehouse = Warehouse::forUnit((int) $plan->sppg_unit_id, Warehouse::TYPE_FOOD);
                    $existing->forceFill([
                        'needed_date' => $plan->requirement_date,
                        'field_distribution_plan_id' => $plan->field_distribution_plan_id,
                        'warehouse_id' => $foodWarehouse->getKey(),
                        'procurement_type' => Warehouse::TYPE_FOOD,
                        'notes' => 'Disinkronkan otomatis dari kebutuhan bahan '.$plan->plan_number.'.',
                    ])->save();
                    $this->synchronizeItems($existing, $plan);
                    $this->recalculate($existing);
                }

                return $existing->refresh();
            }

            $foodWarehouse = Warehouse::forUnit((int) $plan->sppg_unit_id, Warehouse::TYPE_FOOD);
            $request = ProcurementRequest::query()->create([
                'sppg_unit_id' => $plan->sppg_unit_id,
                'request_date' => now()->toDateString(),
                'needed_date' => $plan->requirement_date,
                'nutrition_requirement_plan_id' => $plan->id,
                'field_distribution_plan_id' => $plan->field_distribution_plan_id,
                'warehouse_id' => $foodWarehouse->getKey(),
                'procurement_type' => Warehouse::TYPE_FOOD,
                'status' => ProcurementRequest::STATUS_DRAFT,
                'price_status' => 'draft',
                'notes' => 'Dibuat otomatis dari kebutuhan bahan '.$plan->plan_number.'.',
                'created_by' => $actor->id,
            ]);

            $this->synchronizeItems($request, $plan);
            $this->recalculate($request);

            return $request->refresh();
        });
    }

    private function synchronizeItems(ProcurementRequest $request, NutritionRequirementPlan $plan): void
    {
        $keptIds = [];

        foreach ($plan->items as $item) {
            $existing = $request->items()
                ->with(['measurementUnit', 'ingredient.measurementUnit'])
                ->where('nutrition_requirement_item_id', $item->id)
                ->first();

            $measurementUnit = $existing?->measurementUnit
                ?? $this->findMeasurementUnit((string) $item->unit_snapshot)
                ?? $item->ingredient?->measurementUnit;

            $requirementQuantity = (float) ($item->total_quantity ?? 0);
            $requirementUnit = trim((string) ($item->unit_snapshot ?? ''));

            if ($requirementQuantity <= 0) {
                $requirementQuantity = (float) ($item->total_quantity_kg ?? 0);
                $requirementUnit = 'kg';
            }

            $purchaseQuantity = (float) ($existing?->requested_quantity ?? 0);
            if ($purchaseQuantity <= 0) {
                $purchaseQuantity = $requirementQuantity > 0 ? $requirementQuantity : 1.0;
            }

            $unitSnapshot = $measurementUnit?->symbol
                ?: $measurementUnit?->code
                ?: ($requirementUnit !== '' ? $requirementUnit : 'unit');

            $legacyKgQuantity = $this->legacyKgQuantity($purchaseQuantity, $measurementUnit, $unitSnapshot);

            $requestItem = $request->items()->updateOrCreate([
                'nutrition_requirement_item_id' => $item->id,
            ], [
                'ingredient_id' => $item->ingredient_id,
                'ingredient_code_snapshot' => $item->ingredient_code_snapshot,
                'ingredient_name_snapshot' => $item->ingredient_name_snapshot,
                'unit_snapshot' => $unitSnapshot,
                'measurement_unit_id' => $measurementUnit?->getKey(),
                'kg_per_unit_snapshot' => null,
                'requirement_quantity_snapshot' => $requirementQuantity > 0
                    ? $requirementQuantity
                    : null,
                'requirement_unit_snapshot' => $requirementUnit !== ''
                    ? $requirementUnit
                    : null,
                'requested_quantity' => $purchaseQuantity,
                'approved_quantity' => (float) ($existing?->approved_quantity ?: $purchaseQuantity),
                'requested_quantity_kg' => $legacyKgQuantity,
                'approved_quantity_kg' => $this->legacyKgQuantity(
                    (float) ($existing?->approved_quantity ?: $purchaseQuantity),
                    $measurementUnit,
                    $unitSnapshot,
                ),
                'estimated_unit_price' => (float) ($existing?->estimated_unit_price ?? $item->estimated_unit_price ?? 0),
                'estimated_total_price' => (float) ($existing?->estimated_total_price ?? 0),
                'notes' => $existing?->notes ?? $item->notes,
            ]);

            $keptIds[] = $requestItem->getKey();
        }

        $request->items()
            ->whereNotNull('nutrition_requirement_item_id')
            ->when(
                $keptIds !== [],
                fn ($query) => $query->whereNotIn('id', $keptIds),
            )
            ->delete();
    }

    public function submit(ProcurementRequest $request): void
    {
        $user = $this->authenticatedUser();
        $this->ensurePermission($user, $request->procurement_type === Warehouse::TYPE_NON_FOOD ? 'non_food_procurement.submit' : 'procurement.submit');
        $this->ensureUnitAccess($user, $request->sppg_unit_id);
        $this->ensureStatus($request, [ProcurementRequest::STATUS_DRAFT, ProcurementRequest::STATUS_REVISION]);
        $this->assertWarehouseIntegrity($request);

        if (! $request->items()->exists()) {
            throw ValidationException::withMessages(['items' => 'Daftar bahan masih kosong.']);
        }

        if ($request->items()->where(function ($query): void {
            $query->whereNull('requested_quantity')
                ->orWhere('requested_quantity', '<=', 0);
        })->exists()) {
            throw ValidationException::withMessages([
                'items' => 'Setiap bahan harus memiliki jumlah lebih dari 0 sesuai satuan pembelian.',
            ]);
        }

        if ($request->items()->whereNull('measurement_unit_id')->exists()) {
            throw ValidationException::withMessages([
                'items' => 'Satuan pembelian wajib dipilih untuk seluruh bahan.',
            ]);
        }

        $this->recalculate($request);
        $request->forceFill([
            'status' => ProcurementRequest::STATUS_SUBMITTED,
            'price_status' => 'editing',
            'submitted_by' => $user->id,
            'submitted_at' => now(),
            'approved_by' => null,
            'approved_at' => null,
            'price_finalized_by' => null,
            'price_finalized_at' => null,
            'finance_notes' => null,
        ])->save();
    }

    /**
     * Ahli Gizi dan Pengawas Keuangan boleh mengubah harga selama belum dikunci Kepala SPPG.
     */
    public function savePrices(ProcurementRequest $request): void
    {
        $user = $this->authenticatedUser();
        $this->ensurePermission($user, 'procurement.price_input');
        $this->ensureUnitAccess($user, $request->sppg_unit_id);

        if (! $request->priceIsEditable()) {
            throw ValidationException::withMessages([
                'price' => 'Harga sudah dikunci atau status permintaan tidak mengizinkan perubahan.',
            ]);
        }

        $this->recalculate($request);
    }

    /** Pengawas Keuangan memverifikasi, bukan menetapkan harga final. */
    public function verifyByFinance(ProcurementRequest $request, ?string $notes = null): void
    {
        $user = $this->authenticatedUser();
        $this->ensurePermission($user, 'procurement.approve');
        $this->ensureUnitAccess($user, $request->sppg_unit_id);
        $this->ensureStatus($request, [ProcurementRequest::STATUS_SUBMITTED]);
        $this->ensureNotSelfApproval($request, $user);
        $this->assertSupplierAndPriceComplete($request);

        $request->items()->get()->each(function ($item): void {
            $item->forceFill([
                'approved_quantity' => $item->requested_quantity,
                'approved_quantity_kg' => $item->requested_quantity_kg,
            ])->save();
        });

        $this->recalculate($request);
        $request->forceFill([
            'status' => ProcurementRequest::STATUS_FINANCE_VERIFIED,
            'price_status' => 'finance_verified',
            'finance_notes' => filled($notes) ? $notes : $request->finance_notes,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ])->save();
    }

    /** Kepala SPPG menetapkan dan mengunci harga final. */
    public function finalizePriceByHead(ProcurementRequest $request, ?string $notes = null): void
    {
        $user = $this->authenticatedUser();
        $this->ensurePermission($user, 'procurement.finalize_price');
        $this->ensureUnitAccess($user, $request->sppg_unit_id);
        $this->ensureStatus($request, [ProcurementRequest::STATUS_FINANCE_VERIFIED]);
        $this->ensureNotSelfApproval($request, $user);
        $this->assertSupplierAndPriceComplete($request);
        $this->recalculate($request);

        $request->forceFill([
            'status' => ProcurementRequest::STATUS_APPROVED,
            'price_status' => 'finalized',
            'price_finalized_by' => $user->id,
            'price_finalized_at' => now(),
            'finance_notes' => filled($notes) ? trim(($request->finance_notes ? $request->finance_notes."\n" : '').$notes) : $request->finance_notes,
        ])->save();
    }

    public function requestRevision(ProcurementRequest $request, string $notes): void
    {
        $user = $this->authenticatedUser();

        if (! ($user->can('procurement.approve') || $user->can('procurement.finalize_price'))) {
            throw ValidationException::withMessages(['permission' => 'Anda tidak memiliki izin meminta revisi.']);
        }

        $this->ensureUnitAccess($user, $request->sppg_unit_id);
        $this->ensureStatus($request, [
            ProcurementRequest::STATUS_SUBMITTED,
            ProcurementRequest::STATUS_FINANCE_VERIFIED,
        ]);
        $this->ensureNotSelfApproval($request, $user);

        if (blank($notes)) {
            throw ValidationException::withMessages(['finance_notes' => 'Catatan revisi wajib diisi.']);
        }

        $request->forceFill([
            'status' => ProcurementRequest::STATUS_REVISION,
            'price_status' => 'revision',
            'finance_notes' => $notes,
            'approved_by' => null,
            'approved_at' => null,
            'price_finalized_by' => null,
            'price_finalized_at' => null,
        ])->save();
    }

    public function markOrdered(ProcurementRequest $request): void
    {
        $user = $this->authenticatedUser();
        $this->ensurePermission($user, $request->procurement_type === Warehouse::TYPE_NON_FOOD ? 'non_food_procurement.order' : 'procurement.order');
        $this->ensureUnitAccess($user, $request->sppg_unit_id);
        $this->ensureStatus($request, [ProcurementRequest::STATUS_APPROVED]);
        $this->assertWarehouseIntegrity($request);

        if ($request->price_status !== 'finalized') {
            throw ValidationException::withMessages([
                'price' => 'Harga belum ditetapkan dan dikunci oleh Kepala SPPG.',
            ]);
        }

        DB::transaction(function () use ($request, $user): void {
            $this->assertSupplierAndPriceComplete($request);
            $this->recalculate($request);
            $request->forceFill([
                'status' => ProcurementRequest::STATUS_ORDERED,
                'ordered_by' => $user->id,
                'ordered_at' => now(),
            ])->save();

            app(StockReceiptService::class)
                ->createGroupedFromProcurementRequest($request->refresh()->load('items'));
        });
    }

    public function recalculate(ProcurementRequest $request): void
    {
        $request->load('items');

        foreach ($request->items as $item) {
            if ($request->itemsAreEditable()) {
                $item->approved_quantity = $item->requested_quantity;
                $item->approved_quantity_kg = $item->requested_quantity_kg;
            }

            $usesApprovedQuantity = in_array($request->status, [
                ProcurementRequest::STATUS_FINANCE_VERIFIED,
                ProcurementRequest::STATUS_APPROVED,
                ProcurementRequest::STATUS_ORDERED,
            ], true);

            $quantityForPrice = $usesApprovedQuantity
                ? (float) ($item->approved_quantity ?: $item->requested_quantity ?: 0)
                : (float) ($item->requested_quantity ?: 0);

            $item->estimated_total_price =
                $quantityForPrice * (float) $item->estimated_unit_price;
            $item->save();
        }

        $request->forceFill([
            'total_items' => $request->items()->count(),
            'estimated_total_amount' => $request->items()->sum('estimated_total_price'),
        ])->save();
    }

    private function assertWarehouseIntegrity(ProcurementRequest $request): void
    {
        $type = $request->procurement_type ?: Warehouse::TYPE_FOOD;
        if (! in_array($type, [Warehouse::TYPE_FOOD, Warehouse::TYPE_NON_FOOD], true)) {
            throw ValidationException::withMessages(['warehouse' => 'Jenis pengadaan tidak dikenali.']);
        }

        $warehouse = $request->warehouse;
        if (! $warehouse && $type === Warehouse::TYPE_FOOD) {
            $warehouse = Warehouse::forUnit((int) $request->sppg_unit_id, Warehouse::TYPE_FOOD);
            $request->forceFill([
                'warehouse_id' => $warehouse->getKey(),
                'procurement_type' => Warehouse::TYPE_FOOD,
            ])->save();
        }

        if (! $warehouse
            || (int) $warehouse->sppg_unit_id !== (int) $request->sppg_unit_id
            || $warehouse->type !== $type
            || ! $warehouse->is_active) {
            throw ValidationException::withMessages([
                'warehouse' => 'Gudang tujuan pengadaan tidak aktif atau tidak sesuai dengan jenis barang.',
            ]);
        }

        $invalidItems = $type === Warehouse::TYPE_NON_FOOD
            ? $request->items()->where(function ($query): void {
                $query->whereNull('non_food_item_id')->orWhereNotNull('ingredient_id');
            })->exists()
            : $request->items()->where(function ($query): void {
                $query->whereNull('ingredient_id')->orWhereNotNull('non_food_item_id');
            })->exists();

        if ($invalidItems) {
            throw ValidationException::withMessages([
                'items' => 'Daftar barang tercampur antara Gudang Pangan dan Gudang Non-Pangan.',
            ]);
        }
    }

    private function assertSupplierAndPriceComplete(ProcurementRequest $request): void
    {
        $request->loadMissing('items');

        if ($request->items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Daftar bahan masih kosong.']);
        }

        if ($request->items->contains(fn ($item): bool => blank($item->supplier_id))) {
            throw ValidationException::withMessages([
                'items' => 'Supplier wajib dipilih Staf Gudang untuk seluruh bahan.',
            ]);
        }

        if ($request->items->contains(fn ($item): bool => (float) $item->estimated_unit_price <= 0)) {
            throw ValidationException::withMessages([
                'items' => 'Harga satuan wajib diisi untuk seluruh bahan.',
            ]);
        }

        if ($request->items->contains(fn ($item): bool => blank($item->measurement_unit_id)
            || (float) $item->requested_quantity <= 0
        )) {
            throw ValidationException::withMessages([
                'items' => 'Jumlah dan satuan pembelian wajib valid untuk seluruh bahan.',
            ]);
        }
    }

    private function findMeasurementUnit(string $snapshot): ?MeasurementUnit
    {
        $snapshot = trim($snapshot);

        if ($snapshot === '') {
            return null;
        }

        return MeasurementUnit::query()
            ->where('is_active', true)
            ->where(function ($query) use ($snapshot): void {
                $query->where('code', $snapshot)
                    ->orWhere('symbol', $snapshot);
            })
            ->first();
    }

    private function legacyKgQuantity(
        float $quantity,
        ?MeasurementUnit $measurementUnit,
        ?string $unitSnapshot = null,
    ): float {
        $code = strtolower(trim((string) ($measurementUnit?->code ?? '')));
        $symbol = strtolower(trim((string) ($measurementUnit?->symbol ?? $unitSnapshot ?? '')));

        return in_array($code, ['kg', 'kilogram'], true)
            || in_array($symbol, ['kg', 'kilogram'], true)
                ? round($quantity, 4)
                : 0.0;
    }

    /** @param array<int, string> $allowed */
    private function ensureStatus(ProcurementRequest $request, array $allowed): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'Status permintaan pembelian tidak valid untuk proses ini.',
            ]);
        }
    }

    private function ensureNotSelfApproval(ProcurementRequest $request, User $user): void
    {
        if (in_array($user->id, [$request->created_by, $request->submitted_by], true)) {
            throw ValidationException::withMessages([
                'approval' => 'Pengaju tidak boleh menyetujui atau menetapkan permintaannya sendiri.',
            ]);
        }
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => 'Sesi pengguna tidak tersedia.']);
        }

        return $user;
    }

    private function ensurePermission(User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            throw ValidationException::withMessages([
                'permission' => 'Anda tidak memiliki izin untuk menjalankan proses ini.',
            ]);
        }
    }

    private function ensureUnitAccess(User $user, int $unitId): void
    {
        if (! app(SystemUnit::class)->owns($unitId)) {
            throw ValidationException::withMessages([
                'unit' => 'Data bukan milik Unit SPPG aktif Anda.',
            ]);
        }
    }
}
