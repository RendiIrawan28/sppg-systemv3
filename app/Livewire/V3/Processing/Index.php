<?php

namespace App\Livewire\V3\Processing;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use App\Enums\ProcessingTemperatureCheckpoint;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\ProcessingBatch;
use App\Models\ProcessingDocumentation;
use App\Models\ProcessingReturn;
use App\Services\ProcessingReturnService;
use App\Services\ProcessingWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithFileUploads;

    public ?int $selectedId = null;

    public string $actualOutputQuantity = '';

    public string $actualOutputUnit = '';

    public string $notes = '';

    /** @var array<int, array<string, mixed>> */
    public array $cookedProducts = [];

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $cookedPhotos = [];

    public array $returnQuantities = [];

    public array $returnReasons = [];

    public string $reviewNotes = '';

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('processing.view'), 403);
    }

    public function select(int $id): void
    {
        $batch = $this->record($id)->load([
            'temperatureLogs',
            'documentations',
        ]);
        $this->selectedId = $id;
        $this->actualOutputQuantity = $batch->actual_output_quantity !== null
            ? (string) $batch->actual_output_quantity
            : '';
        $this->actualOutputUnit = (string) ($batch->actual_output_unit ?: $batch->target_output_unit);
        $this->notes = (string) $batch->notes;
        $this->reviewNotes = (string) $batch->review_notes;
        $this->cookedProducts = $batch->temperatureLogs
            ->where('checkpoint', ProcessingTemperatureCheckpoint::Final)
            ->values()
            ->map(function ($temperature) use ($batch): array {
                $documentation = $batch->documentations->first(
                    fn ($photo): bool => Str::lower(trim((string) $photo->caption))
                        === Str::lower(trim((string) $temperature->product_name)),
                );

                return [
                    'temperature_id' => $temperature->id,
                    'documentation_id' => $documentation?->id,
                    'product_name' => $temperature->product_name,
                    'temperature_celsius' => $temperature->temperature_celsius,
                    'cooked_at' => $temperature->checked_at?->format('Y-m-d\TH:i'),
                    'photo_path' => $documentation?->photo_path,
                    'notes' => $temperature->notes,
                ];
            })
            ->all();
        $this->cookedPhotos = [];
        if ($batch->state === ProcessingBatchState::InProgress && $this->cookedProducts === []) {
            $this->addCookedProduct();
        }
    }

    public function addCookedProduct(): void
    {
        $this->cookedProducts[] = [
            'temperature_id' => null,
            'documentation_id' => null,
            'product_name' => '',
            'temperature_celsius' => '',
            'cooked_at' => now()->format('Y-m-d\TH:i'),
            'photo_path' => null,
            'notes' => '',
        ];
    }

    public function removeCookedProduct(int $index): void
    {
        unset($this->cookedProducts[$index], $this->cookedPhotos[$index]);
        $this->cookedProducts = array_values($this->cookedProducts);
        $this->cookedPhotos = array_values($this->cookedPhotos);
    }

    public function save(): void
    {
        abort_unless($this->allowed('processing.update'), 403);
        $batch = $this->record($this->selectedId);
        abort_unless($batch->state === ProcessingBatchState::InProgress, 422);

        $this->validate([
            'actualOutputQuantity' => ['required', 'numeric', 'gt:0'],
            'actualOutputUnit' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'cookedProducts' => ['required', 'array', 'min:1'],
            'cookedProducts.*.product_name' => ['required', 'string', 'max:255'],
            'cookedProducts.*.temperature_celsius' => ['required', 'numeric', 'between:-30,150'],
            'cookedProducts.*.cooked_at' => ['required', 'date'],
            'cookedProducts.*.notes' => ['nullable', 'string', 'max:1000'],
            'cookedPhotos.*' => ['nullable', 'image', 'max:5120'],
        ]);

        foreach ($this->cookedProducts as $index => $product) {
            if (blank($product['photo_path'] ?? null)
                && ! (($this->cookedPhotos[$index] ?? null) instanceof TemporaryUploadedFile)) {
                throw ValidationException::withMessages([
                    "cookedPhotos.$index" => 'Foto makanan matang wajib dipilih.',
                ]);
            }
        }

        DB::transaction(function () use ($batch): void {
            $batch->update([
                'actual_output_quantity' => $this->actualOutputQuantity,
                'actual_output_unit' => trim($this->actualOutputUnit),
                'product_name' => collect($this->cookedProducts)
                    ->pluck('product_name')
                    ->filter()
                    ->implode(', '),
                'notes' => filled($this->notes) ? trim($this->notes) : null,
                'petugas_id' => $batch->petugas_id ?: auth()->id(),
                'petugas_name_snapshot' => $batch->petugas_name_snapshot ?: auth()->user()->name,
                'updated_by' => auth()->id(),
            ]);

            $keptTemperatures = [];
            $keptDocumentations = [];
            foreach ($this->cookedProducts as $index => $product) {
                $temperature = $batch->temperatureLogs()->updateOrCreate(
                    ['id' => $product['temperature_id'] ?: null],
                    [
                        'checked_at' => $product['cooked_at'],
                        'checkpoint' => ProcessingTemperatureCheckpoint::Final,
                        'product_name' => trim($product['product_name']),
                        'temperature_celsius' => $product['temperature_celsius'],
                        'minimum_temperature' => null,
                        'maximum_temperature' => null,
                        'corrective_action' => null,
                        'measured_by' => auth()->id(),
                        'measured_name_snapshot' => auth()->user()->name,
                        'notes' => filled($product['notes'] ?? null) ? trim($product['notes']) : null,
                        'sort_order' => $index + 1,
                    ],
                );
                $keptTemperatures[] = $temperature->id;

                $oldDocumentation = filled($product['documentation_id'] ?? null)
                    ? $batch->documentations()->find($product['documentation_id'])
                    : null;
                $newPhoto = $this->cookedPhotos[$index] ?? null;
                $path = $product['photo_path'] ?? null;
                if ($newPhoto instanceof TemporaryUploadedFile) {
                    $path = $newPhoto->store(
                        'processing/'.now()->format('Y/m/d'),
                        'public',
                    );
                }

                try {
                    $documentation = $batch->documentations()->updateOrCreate(
                        ['id' => $product['documentation_id'] ?: null],
                        [
                            'documentation_type' => 'after',
                            'caption' => trim($product['product_name']),
                            'photo_path' => $path,
                            'captured_at' => $product['cooked_at'],
                            'created_by' => auth()->id(),
                            'sort_order' => $index + 1,
                        ],
                    );
                } catch (Throwable $exception) {
                    if ($newPhoto instanceof TemporaryUploadedFile && $path) {
                        Storage::disk('public')->delete($path);
                    }
                    throw $exception;
                }

                if ($newPhoto instanceof TemporaryUploadedFile
                    && $oldDocumentation?->photo_path
                    && $oldDocumentation->photo_path !== $path) {
                    Storage::disk('public')->delete($oldDocumentation->photo_path);
                }
                $keptDocumentations[] = $documentation->id;
            }

            $batch->temperatureLogs()->whereNotIn('id', $keptTemperatures)->delete();
            $batch->documentations()
                ->whereNotIn('id', $keptDocumentations)
                ->get()
                ->each(function (ProcessingDocumentation $documentation): void {
                    if ($documentation->photo_path) {
                        Storage::disk('public')->delete($documentation->photo_path);
                    }
                    $documentation->delete();
                });
        });

        $this->select($batch->id);
        session()->flash('v3.status', 'Data hasil Pengolahan disimpan.');
    }

    public function start(ProcessingWorkflow $workflow): void
    {
        $batch = $workflow->start($this->record($this->selectedId), auth()->user());
        $this->select($batch->id);
    }

    public function complete(ProcessingWorkflow $workflow): void
    {
        $this->save();
        $batch = $workflow->complete($this->record($this->selectedId), auth()->user());
        $this->select($batch->id);
        session()->flash(
            'v3.status',
            'Pengolahan selesai. Hasil sudah tersedia otomatis di Divisi Pemorsian.',
        );
    }

    public function submitReturn(int $usageId, ProcessingReturnService $service): void
    {
        abort_unless($this->allowed('processing.update'), 403);
        $data = $this->validate([
            "returnQuantities.$usageId" => ['required', 'numeric', 'gt:0'],
            "returnReasons.$usageId" => ['nullable', 'string', 'max:1000'],
        ]);
        $batch = $this->record($this->selectedId);
        $usage = $batch->materialUsages()->findOrFail($usageId);
        $service->submit(
            $batch,
            $usage,
            (float) $data['returnQuantities'][$usageId],
            (string) ($data['returnReasons'][$usageId] ?? ''),
            auth()->user(),
        );
        unset($this->returnQuantities[$usageId], $this->returnReasons[$usageId]);
        session()->flash('v3.status', 'Retur diajukan dan menunggu verifikasi Gudang.');
    }

    public function submit(ProcessingWorkflow $workflow): void
    {
        $batch = $workflow->submit($this->record($this->selectedId), auth()->user());
        $this->select($batch->id);
    }

    public function approve(ProcessingWorkflow $workflow): void
    {
        $batch = $workflow->verify(
            $this->record($this->selectedId),
            auth()->user(),
            filled($this->reviewNotes) ? trim($this->reviewNotes) : null,
        );
        $this->select($batch->id);
    }

    public function requestRevision(ProcessingWorkflow $workflow): void
    {
        $this->validate(['reviewNotes' => ['required', 'string', 'max:2000']]);
        $batch = $workflow->requestRevision(
            $this->record($this->selectedId),
            auth()->user(),
            $this->reviewNotes,
        );
        $this->select($batch->id);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $records = ProcessingBatch::query()
            ->with([
                'materialUsages.returns',
                'temperatureLogs',
                'documentations',
                'petugas',
            ])
            ->where('sppg_unit_id', $unit->id)
            ->latest('production_date')
            ->latest('id')
            ->get();
        $selected = $this->selectedId
            ? $records->firstWhere('id', $this->selectedId)
            : null;

        return view('livewire.v3.processing.index', [
            ...$this->shellData($unit),
            'records' => $records,
            'selected' => $selected,
            'canEdit' => $this->allowed('processing.update'),
            'canSubmit' => $this->allowed('processing.submit'),
            'canApprove' => $this->allowed('processing.approve'),
            'canExport' => $this->allowed('processing.export'),
            'statusLabels' => OperationalReportStatus::options(),
        ])->layout('layouts.v3', ['title' => 'Pengolahan']);
    }

    private function record(?int $id): ProcessingBatch
    {
        return ProcessingBatch::query()
            ->where('sppg_unit_id', $this->currentUnit()->id)
            ->findOrFail($id);
    }
}
