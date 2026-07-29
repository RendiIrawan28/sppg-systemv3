<?php

namespace App\Livewire\V3\WasteHandovers;

use App\Enums\OperationalReportStatus;
use App\Enums\WasteDivision;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\CleaningSession;
use App\Models\PreparationSession;
use App\Models\WashingSession;
use App\Models\WasteHandoverReport;
use App\Services\OperationalReportApprovalService;
use App\Services\WasteHandoverWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Form extends Component
{
    use InteractsWithV3Shell;
    use WithFileUploads;

    public ?int $reportId = null;
    public array $data = [];
    public array $items = [];
    public array $uploads = [];
    public string $workflowNotes = '';

    public function mount(?int $report = null): void
    {
        $this->reportId = $report;
        if ($report) {
            $record = $this->record();
            abort_unless($this->canForDivision($record->division_type, 'view'), 403);
            $this->fillRecord($record);
            return;
        }

        $division = (string) request()->query('division', '');
        $available = $this->divisionOptions('update');
        if (! isset($available[$division])) {
            $division = (string) array_key_first($available);
        }
        abort_if($division === '', 403);

        $unit = $this->currentUnit();
        $actor = auth()->user();
        $sourceType = trim((string) request()->query('source_type', '')) ?: null;
        $sourceId = request()->integer('source_id') ?: null;
        if ($sourceType && $sourceId) {
            $existing = WasteHandoverReport::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->first();
            if ($existing) {
                abort_unless($this->canForDivision($existing->division_type, 'view'), 403);
                $this->reportId = $existing->getKey();
                $this->fillRecord($existing->load('items'));
                return;
            }
        }
        $source = $this->sourceContext($sourceType, $sourceId, $unit->getKey());
        if ($source !== null) {
            $division = $source['division'];
            abort_unless(isset($available[$division]), 403);
        }

        $this->data = [
            'division_type' => $division,
            'report_date' => $source['report_date'] ?? now()->toDateString(),
            'effective_date' => now()->toDateString(),
            'handed_over_at' => now()->format('Y-m-d\TH:i'),
            'document_revision' => '00',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_reference' => $source['source_reference'] ?? request()->query('source_reference'),
            'first_party_name' => $actor?->name ?? '',
            'first_party_position' => $actor?->getRoleNames()->first() ?? 'Petugas Divisi',
            'first_party_address' => $unit->address ?? '',
            'second_party_name' => '',
            'second_party_position' => '',
            'second_party_address' => '',
            'notes' => '',
        ];
        if (! empty($source['items'])) {
            $this->items = $source['items'];
        } else {
            $this->addItem();
        }
    }

    public function addItem(): void
    {
        $this->items[] = ['id' => null, 'waste_type' => '', 'quantity' => '', 'unit' => 'kg', 'notes' => '', 'photo_path' => null];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index], $this->uploads[$index]);
        $this->items = array_values($this->items);
        $this->uploads = array_values($this->uploads);
    }

    public function save(): void
    {
        $divisionValues = array_keys($this->divisionOptions('update'));
        $rules = [
            'data.division_type' => ['required', Rule::in($divisionValues)],
            'data.report_date' => ['required', 'date'],
            'data.effective_date' => ['nullable', 'date'],
            'data.handed_over_at' => ['required', 'date'],
            'data.document_revision' => ['required', 'string', 'max:20'],
            'data.source_type' => ['nullable', 'string', 'max:80'],
            'data.source_id' => ['nullable', 'integer'],
            'data.source_reference' => ['nullable', 'string', 'max:255'],
            'data.first_party_name' => ['required', 'string', 'max:255'],
            'data.first_party_position' => ['required', 'string', 'max:255'],
            'data.first_party_address' => ['required', 'string', 'max:2000'],
            'data.second_party_name' => ['required', 'string', 'max:255'],
            'data.second_party_position' => ['required', 'string', 'max:255'],
            'data.second_party_address' => ['required', 'string', 'max:2000'],
            'data.notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.waste_type' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
            'uploads.*.photo' => ['nullable', 'image', 'max:5120'],
        ];
        $validated = $this->validate($rules);
        $actor = auth()->user();
        $unit = $this->currentUnit();

        DB::transaction(function () use ($validated, $actor, $unit): void {
            $record = $this->reportId ? $this->record() : new WasteHandoverReport();
            abort_unless($this->canForDivision(WasteDivision::from($validated['data']['division_type']), 'update'), 403);
            abort_unless(! $record->exists || $record->isEditable(), 403);

            $record->fill($validated['data']);
            $record->sppg_unit_id = $unit->getKey();
            $record->petugas_id = $actor->getKey();
            $record->petugas_name_snapshot = $actor->name;
            $record->updated_by = $actor->getKey();
            if (! $record->exists) {
                $record->created_by = $actor->getKey();
                $record->status = OperationalReportStatus::Draft;
                $record->source_system = 'web_v3_shared_waste';
            }
            $record->save();

            $kept = [];
            foreach ($this->items as $index => $row) {
                $item = ! empty($row['id']) ? $record->items()->findOrFail($row['id']) : $record->items()->make();
                $photoPath = $row['photo_path'] ?? null;
                $upload = $this->uploads[$index]['photo'] ?? null;
                if ($upload instanceof TemporaryUploadedFile) {
                    $photoPath = $upload->store('v3/waste-handovers/'.now()->format('Y/m'), 'public');
                }
                $item->fill([
                    'waste_type' => $row['waste_type'],
                    'quantity' => $row['quantity'],
                    'unit' => $row['unit'],
                    'weight_kg' => $row['unit'] === 'kg' ? $row['quantity'] : null,
                    'notes' => $row['notes'] ?: null,
                    'photo_path' => $photoPath,
                    'sort_order' => $index + 1,
                ])->save();
                $kept[] = $item->getKey();
            }
            $record->items()->when($kept !== [], fn ($query) => $query->whereNotIn('id', $kept))->delete();

            $this->linkSource($record, $unit->getKey());

            $this->reportId = $record->getKey();
        });

        $this->fillRecord($this->record()->refresh());
        session()->flash('v3.status', 'Berita acara limbah berhasil disimpan.');
    }

    public function submit(WasteHandoverWorkflow $workflow): void
    {
        $this->save();
        $workflow->submit($this->record(), auth()->user(), trim($this->workflowNotes) ?: null);
        $this->fillRecord($this->record()->refresh());
    }

    public function verify(WasteHandoverWorkflow $workflow): void
    {
        $workflow->verify($this->record(), auth()->user(), trim($this->workflowNotes) ?: null);
        $this->fillRecord($this->record()->refresh());
    }

    public function requestRevision(WasteHandoverWorkflow $workflow): void
    {
        $this->validate(['workflowNotes' => ['required', 'string', 'max:2000']]);
        $workflow->requestRevision($this->record(), auth()->user(), $this->workflowNotes);
        $this->fillRecord($this->record()->refresh());
    }

    public function delete(): void
    {
        $record = $this->record();
        abort_unless($record->isEditable() && $this->canForDivision($record->division_type, 'delete'), 403);
        foreach ($record->items as $item) {
            if ($item->photo_path) Storage::disk('public')->delete($item->photo_path);
        }
        $this->unlinkSource($record);
        $record->delete();
        session()->flash('v3.status', 'Berita acara limbah dihapus.');
        $this->redirectRoute('v3.waste-handovers.index', navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $record = $this->reportId ? $this->record() : null;
        $editable = ! $record || $record->isEditable();
        $status = $record?->status;
        $approval = app(OperationalReportApprovalService::class);
        $actor = auth()->user();
        $canReview = $record && $this->canForDivision($record->division_type, 'approve')
            && $approval->isReviewable($status)
            && (($status === OperationalReportStatus::Submitted && ! $approval->isHeadSppg($actor))
                || ($status === OperationalReportStatus::DivisionApproved && $approval->isHeadSppg($actor)));

        return view('livewire.v3.waste-handovers.form', [
            ...$this->shellData($unit),
            'record' => $record,
            'editable' => $editable,
            'divisionOptions' => $this->divisionOptions('update') + $this->divisionOptions('view'),
            'canSubmit' => $record && $editable && $this->canForDivision($record->division_type, 'submit'),
            'canReview' => $canReview,
        ])->layout('layouts.v3', ['title' => $record ? 'Berita Acara Limbah' : 'Buat Berita Acara Limbah']);
    }

    private function record(): WasteHandoverReport
    {
        return WasteHandoverReport::query()
            ->with('items')
            ->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->findOrFail($this->reportId);
    }

    private function fillRecord(WasteHandoverReport $record): void
    {
        $this->data = [
            'division_type' => $record->division_type->value,
            'report_date' => $record->report_date?->format('Y-m-d'),
            'effective_date' => $record->effective_date?->format('Y-m-d'),
            'handed_over_at' => $record->handed_over_at?->format('Y-m-d\TH:i'),
            'document_revision' => $record->document_revision ?: '00',
            'source_type' => $record->source_type,
            'source_id' => $record->source_id,
            'source_reference' => $record->source_reference,
            'first_party_name' => $record->first_party_name,
            'first_party_position' => $record->first_party_position,
            'first_party_address' => $record->first_party_address,
            'second_party_name' => $record->second_party_name,
            'second_party_position' => $record->second_party_position,
            'second_party_address' => $record->second_party_address,
            'notes' => $record->notes,
        ];
        $this->items = $record->items->map(fn ($item): array => [
            'id' => $item->getKey(),
            'waste_type' => $item->waste_type,
            'quantity' => (string) $item->quantity,
            'unit' => $item->unit ?: 'kg',
            'notes' => $item->notes,
            'photo_path' => null,
        ])->all();
        $this->uploads = [];
        $this->workflowNotes = '';
    }

    /** @return array{division:string,report_date:string,source_reference:string,items:array<int,array<string,mixed>>}|null */
    private function sourceContext(?string $sourceType, ?int $sourceId, int $unitId): ?array
    {
        if (! $sourceType || ! $sourceId) {
            return null;
        }

        if ($sourceType === 'preparation_session') {
            $source = PreparationSession::query()
                ->with('items')
                ->where('sppg_unit_id', $unitId)
                ->findOrFail($sourceId);

            return [
                'division' => WasteDivision::Preparation->value,
                'report_date' => $source->preparation_date?->format('Y-m-d') ?: now()->toDateString(),
                'source_reference' => $source->session_number,
                'items' => $source->items
                    ->filter(fn ($item): bool => (float) ($item->waste_quantity ?? $item->waste_weight_kg) > 0)
                    ->map(fn ($item): array => [
                        'id' => null,
                        'waste_type' => 'Sisa '.$item->ingredient_name_snapshot,
                        'quantity' => (string) ($item->waste_quantity ?? $item->waste_weight_kg),
                        'unit' => $item->unit_snapshot ?: 'kg',
                        'notes' => $item->notes,
                        'photo_path' => null,
                    ])->values()->all(),
            ];
        }

        if ($sourceType === 'washing_session') {
            $source = WashingSession::query()
                ->with('wasteRecords')
                ->where('sppg_unit_id', $unitId)
                ->findOrFail($sourceId);

            return [
                'division' => WasteDivision::Washing->value,
                'report_date' => $source->washing_date?->format('Y-m-d') ?: now()->toDateString(),
                'source_reference' => $source->session_number,
                'items' => $source->wasteRecords->map(fn ($item): array => [
                    'id' => null,
                    'waste_type' => $item->waste_type,
                    'quantity' => (string) $item->quantity,
                    'unit' => $item->unit ?: 'kg',
                    'notes' => $item->notes,
                    'photo_path' => null,
                ])->values()->all(),
            ];
        }

        if ($sourceType === 'cleaning_session') {
            $source = CleaningSession::query()
                ->with('wasteRecords')
                ->where('sppg_unit_id', $unitId)
                ->findOrFail($sourceId);

            return [
                'division' => WasteDivision::Cleaning->value,
                'report_date' => $source->scheduled_date?->format('Y-m-d') ?: now()->toDateString(),
                'source_reference' => $source->session_number,
                'items' => $source->wasteRecords->map(fn ($item): array => [
                    'id' => null,
                    'waste_type' => $item->waste_type,
                    'quantity' => (string) $item->quantity,
                    'unit' => $item->unit ?: 'kg',
                    'notes' => $item->notes,
                    'photo_path' => null,
                ])->values()->all(),
            ];
        }

        abort(422, 'Sumber berita acara limbah tidak didukung.');
    }

    private function linkSource(WasteHandoverReport $record, int $unitId): void
    {
        if (! $record->source_type || ! $record->source_id) {
            return;
        }

        match ($record->source_type) {
            'cleaning_session' => CleaningSession::query()
                ->where('sppg_unit_id', $unitId)->whereKey($record->source_id)
                ->update(['waste_handover_report_id' => $record->getKey(), 'waste_presence' => 'yes']),
            'washing_session' => WashingSession::query()
                ->where('sppg_unit_id', $unitId)->whereKey($record->source_id)
                ->update([
                    'waste_handover_report_id' => $record->getKey(),
                    'waste_handed_over_at' => $record->handed_over_at ?: now(),
                ]),
            'preparation_session' => PreparationSession::query()
                ->where('sppg_unit_id', $unitId)->whereKey($record->source_id)
                ->update(['waste_handover_report_id' => $record->getKey()]),
            default => null,
        };
    }

    private function unlinkSource(WasteHandoverReport $record): void
    {
        if (! $record->source_type || ! $record->source_id) {
            return;
        }

        match ($record->source_type) {
            'cleaning_session' => CleaningSession::query()->whereKey($record->source_id)
                ->where('waste_handover_report_id', $record->getKey())
                ->update(['waste_handover_report_id' => null]),
            'washing_session' => WashingSession::query()->whereKey($record->source_id)
                ->where('waste_handover_report_id', $record->getKey())
                ->update(['waste_handover_report_id' => null, 'waste_handed_over_at' => null]),
            'preparation_session' => PreparationSession::query()->whereKey($record->source_id)
                ->where('waste_handover_report_id', $record->getKey())
                ->update(['waste_handover_report_id' => null]),
            default => null,
        };
    }

    /** @return array<string, string> */
    private function divisionOptions(string $action): array
    {
        return collect(WasteDivision::cases())
            ->filter(fn (WasteDivision $division): bool => $this->canForDivision($division, $action))
            ->mapWithKeys(fn (WasteDivision $division): array => [$division->value => $division->label()])
            ->all();
    }

    private function canForDivision(WasteDivision $division, string $action): bool
    {
        return $this->allowed($division->permissionPrefix().'.'.$action);
    }
}
