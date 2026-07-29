<?php

namespace App\Policies;

use App\Models\ProcurementRequest;
use App\Models\User;
use App\Support\V3\SystemUnit;

class ProcurementRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('procurement.view');
    }

    public function view(User $user, ProcurementRequest $record): bool
    {
        return $user->can('procurement.view')
            && $this->belongsToAccessibleUnit($user, $record);
    }

    /**
     * Pembuatan manual melalui halaman Resource dinonaktifkan.
     * Permission ini tetap dipakai oleh aksi dari Kebutuhan Bahan.
     */
    public function create(User $user): bool
    {
        return $user->can('procurement.create');
    }

    public function update(User $user, ProcurementRequest $record): bool
    {
        if (! $this->belongsToAccessibleUnit($user, $record)) {
            return false;
        }

        return match ($record->status) {
            ProcurementRequest::STATUS_DRAFT,
            ProcurementRequest::STATUS_REVISION => $user->can('procurement.update'),

            ProcurementRequest::STATUS_SUBMITTED => $user->can('procurement.update')
                || $user->can('procurement.select_supplier')
                || $user->can('procurement.price_input')
                || $user->can('procurement.approve'),

            ProcurementRequest::STATUS_FINANCE_VERIFIED => $user->can('procurement.price_input')
                || $user->can('procurement.finalize_price'),

            ProcurementRequest::STATUS_APPROVED => $user->can('procurement.order'),

            ProcurementRequest::STATUS_ORDERED => $user->can('stock.create'),

            default => false,
        };
    }

    public function delete(User $user, ProcurementRequest $record): bool
    {
        return $this->belongsToAccessibleUnit($user, $record)
            && $user->can('procurement.delete')
            && $record->isEditable();
    }

    public function restore(User $user, ProcurementRequest $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProcurementRequest $record): bool
    {
        return false;
    }

    private function belongsToAccessibleUnit(
        User $user,
        ProcurementRequest $record,
    ): bool {
        return app(SystemUnit::class)->owns($record);
    }
}
