<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Enums\WasteDivision;
use App\Models\CleaningSession;
use App\Models\PreparationSession;
use App\Models\User;
use App\Models\WashingSession;
use App\Models\WasteHandoverReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WasteHandoverWorkflow
{
    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    public function normalizeAndValidateSource(array $data, int $unitId, ?int $ignoreReportId = null): array
    {
        $sourceType = filled($data['source_type'] ?? null) ? trim((string) $data['source_type']) : null;
        $sourceId = filled($data['source_id'] ?? null) ? (int) $data['source_id'] : null;

        if (($sourceType === null) xor ($sourceId === null)) {
            throw ValidationException::withMessages([
                'data.source_type' => 'Jenis sumber dan ID sumber harus diisi bersamaan.',
            ]);
        }

        if ($sourceType === null) {
            return $data;
        }

        [$division, $model] = $this->sourceDefinition($sourceType);
        /** @var Model|null $source */
        $source = $model::query()
            ->where('sppg_unit_id', $unitId)
            ->find($sourceId);

        if (! $source) {
            throw ValidationException::withMessages([
                'data.source_id' => 'Sumber berita acara tidak ditemukan pada Unit SPPG aktif.',
            ]);
        }

        $duplicate = WasteHandoverReport::query()
            ->where('sppg_unit_id', $unitId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->when($ignoreReportId, fn ($query) => $query->where('id', '!=', $ignoreReportId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'data.source_id' => 'Sumber ini sudah mempunyai Berita Acara Limbah.',
            ]);
        }

        $data['source_type'] = $sourceType;
        $data['source_id'] = $sourceId;
        $data['division_type'] = $division->value;
        $data['source_reference'] = $this->sourceReference($source);

        return $data;
    }

    public function submit(WasteHandoverReport $report, User $actor, ?string $notes = null): WasteHandoverReport
    {
        $this->ensurePermission($report, $actor, 'submit');

        return DB::transaction(function () use ($report, $actor, $notes): WasteHandoverReport {
            $report = WasteHandoverReport::query()->with('items')->lockForUpdate()->findOrFail($report->getKey());
            if (! $report->canBeSubmitted()) {
                throw ValidationException::withMessages(['status' => 'Berita acara tidak dapat diajukan pada status saat ini.']);
            }

            $this->validateBeforeSubmit($report);
            $fromStatus = $report->status->value;
            $report->update([
                'status' => OperationalReportStatus::Submitted,
                'submitted_by' => $actor->getKey(),
                'submitted_at' => now(),
                'division_approved_by' => null,
                'division_approved_at' => null,
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => null,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($report, $actor, 'submitted', $fromStatus, $report->status->value, $notes);

            return $report->refresh();
        });
    }

    public function verify(WasteHandoverReport $report, User $actor, ?string $notes = null): WasteHandoverReport
    {
        $this->ensurePermission($report, $actor, 'approve');

        return DB::transaction(function () use ($report, $actor, $notes): WasteHandoverReport {
            $report = WasteHandoverReport::query()->lockForUpdate()->findOrFail($report->getKey());
            $approval = app(OperationalReportApprovalService::class);
            $approval->assertCanReviewStage($report->status, $actor);
            $previousStatus = $report->status->value;
            $nextStatus = $approval->nextApprovedStatus($report->status, $actor);
            $updates = [
                'status' => $nextStatus,
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ];

            if ($nextStatus === OperationalReportStatus::DivisionApproved) {
                $updates += ['division_approved_by' => $actor->getKey(), 'division_approved_at' => now()];
            } else {
                $updates += ['verified_by' => $actor->getKey(), 'verified_at' => now()];
            }

            $report->update($updates);
            $this->writeHistory($report, $actor, $approval->reviewActionName($nextStatus), $previousStatus, $nextStatus->value, $notes);

            return $report->refresh();
        });
    }

    public function requestRevision(WasteHandoverReport $report, User $actor, string $notes): WasteHandoverReport
    {
        $this->ensurePermission($report, $actor, 'approve');

        return DB::transaction(function () use ($report, $actor, $notes): WasteHandoverReport {
            $report = WasteHandoverReport::query()->lockForUpdate()->findOrFail($report->getKey());
            app(OperationalReportApprovalService::class)->assertCanReviewStage($report->status, $actor);
            $previousStatus = $report->status->value;
            $report->update([
                'status' => OperationalReportStatus::RevisionRequired,
                'division_approved_by' => null,
                'division_approved_at' => null,
                'verified_by' => null,
                'verified_at' => null,
                'review_notes' => $notes,
                'updated_by' => $actor->getKey(),
            ]);

            $this->writeHistory($report, $actor, 'revision_requested', $previousStatus, $report->status->value, $notes);

            return $report->refresh();
        });
    }

    public function validateBeforeSubmit(WasteHandoverReport $report): void
    {
        $report->loadMissing('items');
        $errors = [];
        foreach ([
            'first_party_name' => 'Nama pihak pertama wajib diisi.',
            'first_party_position' => 'Jabatan pihak pertama wajib diisi.',
            'first_party_address' => 'Alamat pihak pertama wajib diisi.',
            'second_party_name' => 'Nama pihak kedua wajib diisi.',
            'second_party_position' => 'Jabatan pihak kedua wajib diisi.',
            'second_party_address' => 'Alamat pihak kedua wajib diisi.',
        ] as $field => $message) {
            if (blank($report->{$field})) {
                $errors[$field] = $message;
            }
        }

        if (! $report->handed_over_at) {
            $errors['handed_over_at'] = 'Tanggal dan waktu serah-terima wajib diisi.';
        }
        if ($report->source_type || $report->source_id) {
            try {
                $this->normalizeAndValidateSource($report->only([
                    'source_type', 'source_id', 'division_type', 'source_reference',
                ]), (int) $report->sppg_unit_id, $report->getKey());
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    $errors[$field] = is_array($messages) ? (string) ($messages[0] ?? 'Sumber berita acara tidak valid.') : (string) $messages;
                }
            }
        }
        if ($report->items->isEmpty()) {
            $errors['items'] = 'Minimal satu item limbah wajib tersedia.';
        }

        foreach ($report->items as $index => $item) {
            $number = $index + 1;
            if (blank($item->waste_type)) {
                $errors["items.{$index}.waste_type"] = "Jenis limbah item {$number} wajib diisi.";
            }
            if ((float) $item->quantity <= 0) {
                $errors["items.{$index}.quantity"] = "Jumlah limbah item {$number} harus lebih dari nol.";
            }
            if (blank($item->unit)) {
                $errors["items.{$index}.unit"] = "Satuan limbah item {$number} wajib diisi.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function sourceDefinition(string $sourceType): array
    {
        return match ($sourceType) {
            'preparation_session' => [WasteDivision::Preparation, PreparationSession::class],
            'washing_session' => [WasteDivision::Washing, WashingSession::class],
            'cleaning_session' => [WasteDivision::Cleaning, CleaningSession::class],
            default => throw ValidationException::withMessages([
                'data.source_type' => 'Jenis sumber berita acara tidak didukung.',
            ]),
        };
    }

    private function sourceReference(Model $source): ?string
    {
        foreach (['session_number', 'report_number', 'run_number'] as $field) {
            if (filled($source->getAttribute($field))) {
                return (string) $source->getAttribute($field);
            }
        }

        return class_basename($source).' #'.$source->getKey();
    }

    private function ensurePermission(WasteHandoverReport $report, User $actor, string $action): void
    {
        $division = $report->division_type instanceof WasteDivision
            ? $report->division_type
            : WasteDivision::from((string) $report->division_type);
        abort_unless($actor->can($division->permissionPrefix().'.'.$action), 403);
    }

    private function writeHistory(WasteHandoverReport $report, User $actor, string $action, ?string $fromStatus, string $toStatus, ?string $notes): void
    {
        $report->histories()->create([
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'snapshot' => $report->fresh()->load('items')->toArray(),
        ]);
    }
}
