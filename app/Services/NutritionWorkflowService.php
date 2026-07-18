<?php

namespace App\Services;

use App\Enums\NutritionRecordStatus;
use App\Models\NutritionWorkflowHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NutritionWorkflowService
{
    /**
     * @param array<int, string> $issues
     */
    public function submit(Model $record, array $issues = []): void
    {
        if ($issues !== []) {
            throw ValidationException::withMessages([
                'readiness' => implode("\n", $issues),
            ]);
        }

        $this->transition(
            record: $record,
            allowedFrom: [
                NutritionRecordStatus::Draft,
                NutritionRecordStatus::RevisionRequired,
            ],
            to: NutritionRecordStatus::Submitted,
            action: 'submitted',
            attributes: [
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
                'revision_notes' => null,
            ],
        );
    }

    public function requestRevision(Model $record, string $notes): void
    {
        abort_unless(auth()->user()?->can('nutrition.approve') === true, 403);

        if ((int) $record->getAttribute('submitted_by') === (int) auth()->id()) {
            throw ValidationException::withMessages([
                'status' => 'Pengaju tidak boleh meminta revisi atas dokumen gizi yang diajukannya sendiri.',
            ]);
        }

        if (blank($notes)) {
            throw ValidationException::withMessages([
                'notes' => 'Catatan revisi wajib diisi.',
            ]);
        }

        $this->transition(
            record: $record,
            allowedFrom: [NutritionRecordStatus::Submitted],
            to: NutritionRecordStatus::RevisionRequired,
            action: 'revision_requested',
            notes: $notes,
            attributes: [
                'revision_notes' => $notes,
            ],
        );
    }

    public function approve(Model $record): void
    {
        abort_unless(auth()->user()?->can('nutrition.approve') === true, 403);

        if ((int) $record->getAttribute('submitted_by') === (int) auth()->id()) {
            throw ValidationException::withMessages([
                'status' => 'Pengaju tidak boleh menyetujui dokumen gizi yang diajukannya sendiri.',
            ]);
        }

        $this->transition(
            record: $record,
            allowedFrom: [NutritionRecordStatus::Submitted],
            to: NutritionRecordStatus::Approved,
            action: 'approved',
            attributes: [
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'revision_notes' => null,
            ],
        );
    }

    public function activate(Model $record): void
    {
        abort_unless(auth()->user()?->can('nutrition.approve') === true, 403);

        $this->transition(
            record: $record,
            allowedFrom: [NutritionRecordStatus::Approved],
            to: NutritionRecordStatus::Active,
            action: 'activated',
            attributes: [
                'activated_at' => now(),
            ],
        );
    }

    public function archive(Model $record): void
    {
        $this->transition(
            record: $record,
            allowedFrom: [
                NutritionRecordStatus::Approved,
                NutritionRecordStatus::Active,
            ],
            to: NutritionRecordStatus::Archived,
            action: 'archived',
        );
    }

    /**
     * @param array<int, NutritionRecordStatus> $allowedFrom
     * @param array<string, mixed> $attributes
     */
    private function transition(
        Model $record,
        array $allowedFrom,
        NutritionRecordStatus $to,
        string $action,
        ?string $notes = null,
        array $attributes = [],
    ): void {
        DB::transaction(function () use (
            $record,
            $allowedFrom,
            $to,
            $action,
            $notes,
            $attributes
        ): void {
            $locked = $record->newQuery()
                ->lockForUpdate()
                ->findOrFail($record->getKey());

            $from = $this->normalizeStatus($locked->getAttribute('status'));

            if (! in_array($from, $allowedFrom, true)) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Status %s tidak dapat diproses untuk tindakan ini.',
                        $from->label()
                    ),
                ]);
            }

            $snapshot = $locked->toArray();

            $locked->forceFill([
                'status' => $to,
                ...$attributes,
            ])->save();

            NutritionWorkflowHistory::query()->create([
                'sppg_unit_id' => $locked->getAttribute('sppg_unit_id'),
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'action' => $action,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'notes' => $notes,
                'snapshot' => $snapshot,
                'actor_id' => auth()->id(),
                'created_at' => now(),
            ]);

            $record->refresh();
        });
    }

    private function normalizeStatus(mixed $status): NutritionRecordStatus
    {
        if ($status instanceof NutritionRecordStatus) {
            return $status;
        }

        return NutritionRecordStatus::tryFrom((string) $status)
            ?? NutritionRecordStatus::Draft;
    }
}
