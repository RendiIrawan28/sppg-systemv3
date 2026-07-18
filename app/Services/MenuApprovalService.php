<?php

namespace App\Services;

use App\Enums\MenuStatus;
use App\Models\Menu;
use App\Models\MenuApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuApprovalService
{
    public function __construct(
        private readonly MenuNutritionCalculator $calculator,
        private readonly MenuNutritionWarningService $warningService,
        private readonly MenuDayRevisionService $revisionService,
    ) {
    }

    /**
     * Pengajuan menu individual hanya untuk hasil revisi satu hari yang telah
     * memperoleh izin Kepala SPPG. Pengajuan awal tetap dilakukan dari siklus.
     */
    public function submit(Menu $menu, User $user, ?string $notes = null): Menu
    {
        abort_unless($user->can('menus.submit'), 403);

        if (! $menu->belongsToApprovedOrActiveCycle()) {
            throw ValidationException::withMessages([
                'status' => 'Pengajuan awal dilakukan melalui Siklus Menu. Pengajuan menu individual hanya untuk revisi hari pada siklus yang sudah disetujui atau aktif.',
            ]);
        }

        if (! $this->revisionService->activeRequestForMenu($menu)) {
            throw ValidationException::withMessages([
                'revision' => 'Revisi menu belum memperoleh izin dari Kepala SPPG.',
            ]);
        }

        return DB::transaction(function () use ($menu, $user, $notes): Menu {
            $menu = Menu::query()->lockForUpdate()->findOrFail($menu->getKey());

            if (! $menu->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'Menu tidak dapat diajukan pada status saat ini.',
                ]);
            }

            $blocking = $this->warningService->blockingIssues($menu);

            if ($blocking !== []) {
                throw ValidationException::withMessages([
                    'readiness' => implode("\n", $blocking),
                ]);
            }

            $this->calculator->refresh($menu);
            $previous = $menu->status;

            $menu->update([
                'status' => MenuStatus::PendingReview,
                'submitted_by' => $user->getKey(),
                'submitted_at' => now(),
                'last_revision_submitted_at' => now(),
                'review_notes' => $notes,
            ]);

            $this->revisionService->markSubmitted($menu, $user, $notes);
            $this->record(
                $menu,
                $user,
                'revision_submitted',
                $previous->value,
                MenuStatus::PendingReview->value,
                $notes,
            );

            return $menu->refresh();
        });
    }

    public function approve(Menu $menu, User $user, ?string $notes = null): Menu
    {
        abort_unless($user->can('menus.approve'), 403);

        return DB::transaction(function () use ($menu, $user, $notes): Menu {
            $menu = Menu::query()->lockForUpdate()->findOrFail($menu->getKey());

            if ($menu->status !== MenuStatus::PendingReview) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya revisi menu yang menunggu persetujuan yang dapat disetujui.',
                ]);
            }

            if ((int) $menu->submitted_by === (int) $user->getKey()) {
                throw ValidationException::withMessages([
                    'status' => 'Pengaju tidak boleh menyetujui revisinya sendiri.',
                ]);
            }

            $this->calculator->refresh($menu);
            $newStatus = $menu->belongsToActiveCycle()
                ? MenuStatus::InUse
                : MenuStatus::Approved;

            $menu->update([
                'status' => $newStatus,
                'approved_by' => $user->getKey(),
                'approved_at' => now(),
                'last_revision_approved_at' => now(),
                'review_notes' => $notes,
            ]);

            $this->revisionService->complete($menu, $user, $notes);
            $this->record(
                $menu,
                $user,
                'revision_approved',
                MenuStatus::PendingReview->value,
                $newStatus->value,
                $notes,
            );

            return $menu->refresh();
        });
    }

    public function requestRevision(Menu $menu, User $user, string $notes): Menu
    {
        abort_unless($user->can('menus.approve'), 403);

        if (blank($notes)) {
            throw ValidationException::withMessages([
                'notes' => 'Alasan revisi wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($menu, $user, $notes): Menu {
            $menu = Menu::query()->lockForUpdate()->findOrFail($menu->getKey());

            if ($menu->status !== MenuStatus::PendingReview) {
                throw ValidationException::withMessages([
                    'status' => 'Menu tidak sedang menunggu persetujuan revisi.',
                ]);
            }

            $menu->update([
                'status' => MenuStatus::RevisionRequired,
                'review_notes' => $notes,
            ]);

            $this->revisionService->markChangesRequested($menu, $notes);
            $this->record(
                $menu,
                $user,
                'revision_requested',
                MenuStatus::PendingReview->value,
                MenuStatus::RevisionRequired->value,
                $notes,
            );

            return $menu->refresh();
        });
    }

    private function record(
        Menu $menu,
        User $user,
        string $action,
        string $previous,
        string $new,
        ?string $notes,
    ): void {
        MenuApproval::query()->create([
            'menu_id' => $menu->getKey(),
            'user_id' => $user->getKey(),
            'action' => $action,
            'previous_status' => $previous,
            'new_status' => $new,
            'notes' => $notes,
        ]);
    }
}
