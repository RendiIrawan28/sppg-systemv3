<?php

namespace App\Services;

use App\Enums\MenuDayRevisionStatus;
use App\Enums\MenuStatus;
use App\Enums\NutritionRecordStatus;
use App\Models\Menu;
use App\Models\MenuApproval;
use App\Models\MenuCycleDay;
use App\Models\MenuDayRevisionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuDayRevisionService
{
    public function __construct(
        private readonly MenuCloneService $cloneService,
    ) {
    }

    public function request(
        MenuCycleDay $day,
        User $user,
        string $reason,
        ?string $impactNotes = null,
    ): MenuDayRevisionRequest {
        abort_unless($user->can('menus.submit'), 403);

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan revisi wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($day, $user, $reason, $impactNotes): MenuDayRevisionRequest {
            $day = MenuCycleDay::query()
                ->with(['cycle', 'menu.items'])
                ->lockForUpdate()
                ->findOrFail($day->getKey());

            if (! $day->menu) {
                throw ValidationException::withMessages([
                    'menu' => 'Hari pelayanan belum memiliki menu.',
                ]);
            }

            if (! in_array($day->cycle?->status, [
                NutritionRecordStatus::Approved,
                NutritionRecordStatus::Active,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan revisi hanya dapat dibuat untuk siklus yang sudah disetujui atau aktif.',
                ]);
            }

            if (! in_array($day->menu->status, [MenuStatus::Approved, MenuStatus::InUse], true)) {
                throw ValidationException::withMessages([
                    'menu' => 'Menu belum berada pada status disetujui atau aktif.',
                ]);
            }

            if ($this->openRequestForDay($day)) {
                throw ValidationException::withMessages([
                    'revision' => 'Hari ini masih memiliki proses revisi yang belum selesai.',
                ]);
            }

            $request = MenuDayRevisionRequest::query()->create([
                'sppg_unit_id' => $day->cycle->sppg_unit_id,
                'menu_cycle_id' => $day->menu_cycle_id,
                'menu_cycle_day_id' => $day->getKey(),
                'original_menu_id' => $day->menu_id,
                'status' => MenuDayRevisionStatus::PendingAuthorization,
                'reason' => trim($reason),
                'impact_notes' => filled($impactNotes) ? trim((string) $impactNotes) : null,
                'snapshot' => $this->snapshot($day),
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
            ]);

            $day->update([
                'revision_status' => MenuDayRevisionStatus::PendingAuthorization->value,
                'revision_notes' => trim($reason),
                'revision_submitted_at' => now(),
                'revision_approved_at' => null,
            ]);

            return $request->refresh();
        });
    }

    public function authorize(
        MenuDayRevisionRequest $request,
        User $user,
        ?string $decisionNotes = null,
    ): MenuDayRevisionRequest {
        abort_unless($user->can('menus.approve'), 403);

        return DB::transaction(function () use ($request, $user, $decisionNotes): MenuDayRevisionRequest {
            $request = MenuDayRevisionRequest::query()
                ->with(['day.cycle', 'originalMenu'])
                ->lockForUpdate()
                ->findOrFail($request->getKey());

            if ($request->status !== MenuDayRevisionStatus::PendingAuthorization) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan ini sudah diproses.',
                ]);
            }

            if ((int) $request->requested_by === (int) $user->getKey()) {
                throw ValidationException::withMessages([
                    'status' => 'Pengaju tidak boleh mengizinkan permintaan revisinya sendiri.',
                ]);
            }

            $day = MenuCycleDay::query()
                ->with(['cycle', 'menu'])
                ->lockForUpdate()
                ->findOrFail($request->menu_cycle_day_id);

            if (! $request->originalMenu) {
                throw ValidationException::withMessages([
                    'menu' => 'Menu asal tidak ditemukan.',
                ]);
            }

            $revisionMenu = $this->cloneService->cloneForRevision(
                source: $request->originalMenu,
                day: $day,
                creator: $request->requester()->firstOrFail(),
                reason: $request->reason,
            );

            // Menu aktif pada hari pelayanan tetap memakai menu lama sampai
            // hasil revisi disetujui. Clone disimpan pada revision_menu_id.
            $day->update([
                'revision_status' => MenuDayRevisionStatus::Authorized->value,
                'revision_notes' => filled($decisionNotes) ? trim((string) $decisionNotes) : $request->reason,
                'revision_approved_at' => now(),
            ]);

            $request->update([
                'revision_menu_id' => $revisionMenu->getKey(),
                'status' => MenuDayRevisionStatus::Authorized,
                'decision_notes' => filled($decisionNotes) ? trim((string) $decisionNotes) : null,
                'decided_by' => $user->getKey(),
                'decided_at' => now(),
            ]);

            MenuApproval::query()->create([
                'menu_id' => $revisionMenu->getKey(),
                'user_id' => $user->getKey(),
                'action' => 'revision_authorized',
                'previous_status' => $request->originalMenu->status->value,
                'new_status' => MenuStatus::RevisionRequired->value,
                'notes' => $decisionNotes,
                'snapshot' => [
                    'revision_request_id' => $request->getKey(),
                    'menu_cycle_day_id' => $day->getKey(),
                    'original_menu_id' => $request->original_menu_id,
                ],
            ]);

            return $request->refresh();
        });
    }

    public function reject(
        MenuDayRevisionRequest $request,
        User $user,
        string $decisionNotes,
    ): MenuDayRevisionRequest {
        abort_unless($user->can('menus.approve'), 403);

        if (blank($decisionNotes)) {
            throw ValidationException::withMessages([
                'decision_notes' => 'Alasan penolakan wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($request, $user, $decisionNotes): MenuDayRevisionRequest {
            $request = MenuDayRevisionRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->getKey());

            if ($request->status !== MenuDayRevisionStatus::PendingAuthorization) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan ini sudah diproses.',
                ]);
            }

            if ((int) $request->requested_by === (int) $user->getKey()) {
                throw ValidationException::withMessages([
                    'status' => 'Pengaju tidak boleh menolak permintaan revisinya sendiri.',
                ]);
            }

            $request->update([
                'status' => MenuDayRevisionStatus::Rejected,
                'decision_notes' => trim($decisionNotes),
                'decided_by' => $user->getKey(),
                'decided_at' => now(),
            ]);

            MenuCycleDay::query()
                ->whereKey($request->menu_cycle_day_id)
                ->update([
                    'revision_status' => MenuDayRevisionStatus::Rejected->value,
                    'revision_notes' => trim($decisionNotes),
                    'revision_approved_at' => null,
                ]);

            return $request->refresh();
        });
    }

    public function activeRequestForMenu(Menu $menu): ?MenuDayRevisionRequest
    {
        return MenuDayRevisionRequest::query()
            ->where('revision_menu_id', $menu->getKey())
            ->whereIn('status', [
                MenuDayRevisionStatus::Authorized->value,
                MenuDayRevisionStatus::Submitted->value,
                MenuDayRevisionStatus::ChangesRequested->value,
            ])
            ->latest('id')
            ->first();
    }

    public function openRequestForDay(MenuCycleDay $day): ?MenuDayRevisionRequest
    {
        return MenuDayRevisionRequest::query()
            ->where('menu_cycle_day_id', $day->getKey())
            ->whereIn('status', $this->openStatusValues())
            ->latest('id')
            ->first();
    }

    public function markSubmitted(Menu $menu, User $user, ?string $notes = null): void
    {
        $request = $this->activeRequestForMenu($menu);

        if (! $request || ! in_array($request->status, [
            MenuDayRevisionStatus::Authorized,
            MenuDayRevisionStatus::ChangesRequested,
        ], true)) {
            throw ValidationException::withMessages([
                'revision' => 'Menu tidak memiliki izin revisi yang aktif.',
            ]);
        }

        $request->update([
            'status' => MenuDayRevisionStatus::Submitted,
            'impact_notes' => filled($notes) ? trim((string) $notes) : $request->impact_notes,
        ]);

        MenuCycleDay::query()
            ->whereKey($request->menu_cycle_day_id)
            ->update([
                'revision_status' => MenuDayRevisionStatus::Submitted->value,
                'revision_notes' => filled($notes) ? trim((string) $notes) : $request->reason,
                'revision_submitted_at' => now(),
            ]);
    }

    public function markChangesRequested(Menu $menu, string $notes): void
    {
        $request = $this->activeRequestForMenu($menu);

        if (! $request) {
            return;
        }

        $request->update([
            'status' => MenuDayRevisionStatus::ChangesRequested,
            'decision_notes' => trim($notes),
        ]);

        MenuCycleDay::query()
            ->whereKey($request->menu_cycle_day_id)
            ->update([
                'revision_status' => MenuDayRevisionStatus::ChangesRequested->value,
                'revision_notes' => trim($notes),
            ]);
    }

    public function complete(Menu $menu, User $user, ?string $notes = null): void
    {
        $request = $this->activeRequestForMenu($menu);

        if (! $request || $request->status !== MenuDayRevisionStatus::Submitted) {
            return;
        }

        DB::transaction(function () use ($request, $menu, $user, $notes): void {
            $request = MenuDayRevisionRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->getKey());

            $day = MenuCycleDay::query()
                ->lockForUpdate()
                ->findOrFail($request->menu_cycle_day_id);

            if ((int) $request->revision_menu_id !== (int) $menu->getKey()) {
                throw ValidationException::withMessages([
                    'revision' => 'Menu hasil revisi tidak sesuai dengan permintaan aktif.',
                ]);
            }

            $request->update([
                'status' => MenuDayRevisionStatus::Completed,
                'decision_notes' => filled($notes) ? trim((string) $notes) : $request->decision_notes,
                'completed_by' => $user->getKey(),
                'completed_at' => now(),
            ]);

            // Penggantian menu pada hari pelayanan baru dilakukan setelah
            // hasil revisi benar-benar disetujui Kepala SPPG.
            $day->update([
                'menu_id' => $menu->getKey(),
                'source_menu_id' => $menu->source_menu_id ?: $request->original_menu_id,
                'snapshot_version' => max((int) $day->snapshot_version + 1, (int) $menu->snapshot_version),
                'snapshot_created_at' => $menu->snapshot_created_at ?: now(),
                'revision_status' => MenuDayRevisionStatus::Completed->value,
                'revision_notes' => filled($notes) ? trim((string) $notes) : $request->reason,
                'revision_approved_at' => now(),
            ]);

            app(MenuRevisionRequirementDeltaService::class)
                ->createForCompletedRevision($request->refresh(), $user);
        });
    }

    /** @return array<int, string> */
    private function openStatusValues(): array
    {
        return array_values(array_map(
            static fn (MenuDayRevisionStatus $status): string => $status->value,
            array_filter(
                MenuDayRevisionStatus::cases(),
                static fn (MenuDayRevisionStatus $status): bool => $status->isOpen(),
            ),
        ));
    }

    /** @return array<string, mixed> */
    private function snapshot(MenuCycleDay $day): array
    {
        return [
            'cycle' => [
                'id' => $day->menu_cycle_id,
                'code' => $day->cycle?->code,
                'name' => $day->cycle?->name,
                'status' => $day->cycle?->status?->value,
            ],
            'day' => [
                'id' => $day->getKey(),
                'day_number' => $day->day_number,
                'service_date' => $day->service_date?->toDateString(),
            ],
            'menu' => [
                'id' => $day->menu?->getKey(),
                'code' => $day->menu?->code,
                'name' => $day->menu?->name,
                'status' => $day->menu?->status?->value,
                'revision_number' => $day->menu?->revision_number,
                'components' => $day->menu?->items
                    ->map(fn ($item): array => [
                        'type' => $item->item_type,
                        'name' => $item->name,
                        'audience' => $item->getRawOriginal('menu_audience') ?? 'all',
                    ])
                    ->values()
                    ->all() ?? [],
            ],
        ];
    }
}
