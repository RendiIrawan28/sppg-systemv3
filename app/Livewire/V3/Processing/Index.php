<?php

namespace App\Livewire\V3\Processing;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use App\Enums\ProcessingTemperatureCheckpoint;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\Ingredient;
use App\Models\ProcessingBatch;
use App\Models\ProcessingDocumentation;
use App\Services\ProcessingReturnService;
use App\Services\ProcessingWorkflow;
use App\Services\ProcessingPortioningHandoverService;
use App\Services\PreparationOutputService;
use App\Models\PreparationOutputWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public string $newProductionDate = '';
    public string $newProductName = '';
    public array $manualMaterials = [];

    public string $cancellationReason = '';

    public string $notes = '';

    /** @var array<int, array<string, mixed>> */
    public array $cookedProducts = [];

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $temperaturePhotos = [];

    /** @var array<int, array<string, mixed>> */
    public array $finishedOutputDocumentations = [];

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $finishedOutputPhotos = [];

    public array $returnQuantities = [];

    public array $returnReasons = [];

    public string $reviewNotes = '';

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('processing.view'), 403);
        $this->newProductionDate = today()->format('Y-m-d');
    }

    public function select(int $id): void
    {
        $batch = $this->record($id)->load([
            'materialUsages',
            'temperatureLogs',
            'documentations',
        ]);
        $this->selectedId = $id;
        $this->notes = (string) $batch->notes;
        $this->reviewNotes = (string) $batch->review_notes;
        $primaryOutput = $batch->documentations
            ->where('documentation_type', 'finished_output')
            ->sortBy('sort_order')
            ->first();
        $this->finishedOutputDocumentations = $primaryOutput ? [[
            'documentation_id' => $primaryOutput->id,
            'output_quantity' => $primaryOutput->output_quantity !== null
                ? (string) $primaryOutput->output_quantity
                : '',
            'output_unit' => (string) $primaryOutput->output_unit,
            'caption' => $primaryOutput->caption,
            'captured_at' => $primaryOutput->captured_at?->format('Y-m-d\TH:i')
                ?: now()->format('Y-m-d\TH:i'),
            'photo_path' => $primaryOutput->photo_path,
        ]] : [];
        $this->finishedOutputPhotos = [];
        $this->cookedProducts = $batch->temperatureLogs
            ->where('checkpoint', ProcessingTemperatureCheckpoint::Final)
            ->values()
            ->map(fn ($temperature): array => [
                'temperature_id' => $temperature->id,
                'product_name' => $temperature->product_name,
                'temperature_celsius' => $temperature->temperature_celsius,
                'cooked_at' => $temperature->checked_at?->format('Y-m-d\TH:i'),
                'temperature_photo_path' => $temperature->photo_path,
                'notes' => $temperature->notes,
            ])
            ->all();
        $this->temperaturePhotos = [];
        $this->manualMaterials = $batch->materialUsages->where('source_type', 'manual')->values()->map(fn ($usage) => [
            'id' => $usage->id, 'ingredient_id' => $usage->ingredient_id,
            'material_name' => $usage->material_name, 'quantity' => $usage->quantity,
            'unit_name' => $usage->unit_name, 'notes' => $usage->notes,
        ])->all();
        if ($batch->state === ProcessingBatchState::InProgress && $this->manualMaterials === []) $this->addManualMaterial();
        if ($batch->state === ProcessingBatchState::InProgress && $this->cookedProducts === []) {
            $this->addCookedProduct();
        }
        if ($batch->state === ProcessingBatchState::InProgress && $this->finishedOutputDocumentations === []) {
            $this->addFinishedOutputDocumentation();
        }
    }

    public function addManualMaterial(): void
    {
        $this->manualMaterials[] = ['id' => null, 'ingredient_id' => null, 'material_name' => '', 'quantity' => '', 'unit_name' => '', 'notes' => ''];
    }

    public function removeManualMaterial(int $index): void
    {
        unset($this->manualMaterials[$index]);
        $this->manualMaterials = array_values($this->manualMaterials);
    }

    public function createManualBatch(ProcessingWorkflow $workflow): void
    {
        abort_unless($this->allowed('processing.update'), 403);
        $data = $this->validate([
            'newProductionDate' => ['required', 'date'],
            'newProductName' => ['required', 'string', 'max:255'],
        ]);
        $batch = DB::transaction(function () use ($data, $workflow): ProcessingBatch {
            $batch = ProcessingBatch::create([
                'sppg_unit_id' => $this->currentUnit()->getKey(),
                'production_date' => $data['newProductionDate'],
                'service_date' => $data['newProductionDate'],
                'product_name' => trim($data['newProductName']),
                'menu_name_snapshot' => trim($data['newProductName']),
                'target_output_quantity' => 0,
                'target_output_unit' => 'porsi',
                'created_by' => auth()->id(), 'updated_by' => auth()->id(),
            ]);

            return $workflow->start($batch, auth()->user());
        });
        $this->newProductName = '';
        $this->select($batch->getKey());
    }

    public function addCookedProduct(): void
    {
        $this->cookedProducts[] = [
            'temperature_id' => null,
            'product_name' => $this->selectedId ? $this->record($this->selectedId)->product_name : '',
            'temperature_celsius' => '',
            'cooked_at' => now()->format('Y-m-d\TH:i'),
            'temperature_photo_path' => null,
            'notes' => '',
        ];
    }

    public function removeCookedProduct(int $index): void
    {
        unset($this->cookedProducts[$index], $this->temperaturePhotos[$index]);
        $this->cookedProducts = array_values($this->cookedProducts);
        $this->temperaturePhotos = array_values($this->temperaturePhotos);
    }

    public function addFinishedOutputDocumentation(): void
    {
        if ($this->finishedOutputDocumentations !== []) {
            return;
        }

        $batch = $this->selectedId ? $this->record($this->selectedId) : null;
        $this->finishedOutputDocumentations[] = [
            'documentation_id' => null,
            'output_quantity' => '',
            'output_unit' => '',
            'caption' => $batch?->product_name ?: $batch?->menu_name_snapshot ?: '',
            'captured_at' => now()->format('Y-m-d\TH:i'),
            'photo_path' => null,
        ];
    }

    public function removeFinishedOutputDocumentation(int $index): void
    {
        unset(
            $this->finishedOutputDocumentations[$index],
            $this->finishedOutputPhotos[$index],
        );

        $this->finishedOutputDocumentations = array_values($this->finishedOutputDocumentations);
        $this->finishedOutputPhotos = array_values($this->finishedOutputPhotos);
    }

    public function save(): void
    {
        abort_unless($this->allowed('processing.update'), 403);
        $batch = $this->record($this->selectedId);
        abort_unless($batch->state === ProcessingBatchState::InProgress, 422);

        // Baris kosong otomatis hanya berfungsi sebagai formulir cepat. Jika bahan
        // sudah berasal dari Gudang atau hasil Persiapan, petugas tidak perlu
        // membuat ulang bahan yang sama sebagai catatan manual.
        $this->manualMaterials = collect($this->manualMaterials)
            ->reject(fn (array $material): bool => blank($material['material_name'] ?? null)
                && blank($material['quantity'] ?? null)
                && blank($material['unit_name'] ?? null)
                && blank($material['notes'] ?? null))
            ->values()
            ->all();

        $this->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'cookedProducts' => ['required', 'array', 'min:1'],
            'cookedProducts.*.product_name' => ['required', 'string', 'max:255'],
            'cookedProducts.*.temperature_celsius' => ['required', 'numeric', 'between:-30,150'],
            'cookedProducts.*.cooked_at' => ['required', 'date'],
            'cookedProducts.*.notes' => ['nullable', 'string', 'max:1000'],
            'temperaturePhotos.*' => ['nullable', 'image', 'max:5120'],
            'finishedOutputDocumentations' => ['required', 'array', 'size:1'],
            'finishedOutputDocumentations.*.output_quantity' => ['required', 'numeric', 'gt:0'],
            'finishedOutputDocumentations.*.output_unit' => ['required', 'string', 'max:80'],
            'finishedOutputDocumentations.*.caption' => ['nullable', 'string', 'max:255'],
            'finishedOutputDocumentations.*.captured_at' => ['required', 'date'],
            'finishedOutputPhotos.*' => ['nullable', 'image', 'max:5120'],
            'manualMaterials' => ['required', 'array', 'min:1'],
            'manualMaterials.*.ingredient_id' => ['nullable', 'integer'],
            'manualMaterials.*.material_name' => ['required', 'string', 'max:255'],
            'manualMaterials.*.quantity' => ['required', 'numeric', 'gt:0'],
            'manualMaterials.*.unit_name' => ['required', 'string', 'max:80'],
            'manualMaterials.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach ($this->cookedProducts as $index => $product) {
            if (blank($product['temperature_photo_path'] ?? null)
                && ! (($this->temperaturePhotos[$index] ?? null) instanceof TemporaryUploadedFile)) {
                throw ValidationException::withMessages([
                    "temperaturePhotos.$index" => 'Foto pengukuran suhu makanan wajib dipilih.',
                ]);
            }
        }
        foreach ($this->finishedOutputDocumentations as $index => $documentation) {
            if (blank($documentation['photo_path'] ?? null)
                && ! (($this->finishedOutputPhotos[$index] ?? null) instanceof TemporaryUploadedFile)) {
                throw ValidationException::withMessages([
                    "finishedOutputPhotos.$index" => 'Foto berat atau jumlah makanan jadi wajib dipilih.',
                ]);
            }
        }

        $newPaths = [];
        $oldPaths = [];
        try {
            DB::transaction(function () use ($batch, &$newPaths, &$oldPaths): void {
                $batch->update([
                    'notes' => filled($this->notes) ? trim($this->notes) : null,
                    'petugas_id' => $batch->petugas_id ?: auth()->id(),
                    'petugas_name_snapshot' => $batch->petugas_name_snapshot ?: auth()->user()->name,
                    'updated_by' => auth()->id(),
                ]);

                $keptMaterials = [];
                foreach ($this->manualMaterials as $index => $material) {
                    $usage = $batch->materialUsages()->updateOrCreate(
                        ['id' => $material['id'] ?: null],
                        [
                            'source_type' => 'manual', 'source_id' => null, 'source_item_id' => null,
                            'ingredient_id' => filled($material['ingredient_id']) ? $material['ingredient_id'] : null,
                            'material_name' => trim($material['material_name']),
                            'quantity' => $material['quantity'], 'unit_name' => trim($material['unit_name']),
                            'source_reference' => 'Catatan pemakaian aktual',
                            'received_by' => auth()->id(), 'received_at' => now(),
                            'notes' => filled($material['notes']) ? trim($material['notes']) : null,
                            'sort_order' => $index + 1,
                        ],
                    );
                    $keptMaterials[] = $usage->id;
                }
                $batch->materialUsages()->where('source_type', 'manual')->whereNotIn('id', $keptMaterials)->delete();

                $keptTemperatures = [];
                foreach ($this->cookedProducts as $index => $product) {
                    $oldTemperature = filled($product['temperature_id'] ?? null)
                        ? $batch->temperatureLogs()->find($product['temperature_id'])
                        : null;
                    $newTemperaturePhoto = $this->temperaturePhotos[$index] ?? null;
                    $temperaturePhotoPath = $product['temperature_photo_path'] ?? null;
                    if ($newTemperaturePhoto instanceof TemporaryUploadedFile) {
                        $temperaturePhotoPath = $newTemperaturePhoto->store(
                            'processing/temperature/'.now()->format('Y/m/d'),
                            'public',
                        );
                        $newPaths[] = $temperaturePhotoPath;
                        if ($oldTemperature?->photo_path && $oldTemperature->photo_path !== $temperaturePhotoPath) {
                            $oldPaths[] = $oldTemperature->photo_path;
                        }
                    }

                    $temperature = $batch->temperatureLogs()->updateOrCreate(
                        ['id' => $product['temperature_id'] ?: null],
                        [
                            'checked_at' => $product['cooked_at'],
                            'checkpoint' => ProcessingTemperatureCheckpoint::Final,
                            'product_name' => $batch->product_name ?: $batch->menu_name_snapshot,
                            'temperature_celsius' => $product['temperature_celsius'],
                            'minimum_temperature' => null,
                            'maximum_temperature' => null,
                            'corrective_action' => null,
                            'measured_by' => auth()->id(),
                            'measured_name_snapshot' => auth()->user()->name,
                            'photo_path' => $temperaturePhotoPath,
                            'notes' => filled($product['notes'] ?? null) ? trim($product['notes']) : null,
                            'sort_order' => $index + 1,
                        ],
                    );
                    $keptTemperatures[] = $temperature->id;
                }

                $batch->temperatureLogs()
                    ->where('checkpoint', ProcessingTemperatureCheckpoint::Final)
                    ->whereNotIn('id', $keptTemperatures)
                    ->get()
                    ->each(function ($temperature) use (&$oldPaths): void {
                        if ($temperature->photo_path) {
                            $oldPaths[] = $temperature->photo_path;
                        }
                        $temperature->delete();
                    });

                $keptOutputDocumentations = [];
                foreach ($this->finishedOutputDocumentations as $index => $documentationData) {
                    $oldOutputDocumentation = filled($documentationData['documentation_id'] ?? null)
                        ? $batch->documentations()->find($documentationData['documentation_id'])
                        : null;
                    $newOutputPhoto = $this->finishedOutputPhotos[$index] ?? null;
                    $outputPhotoPath = $documentationData['photo_path'] ?? null;

                    if ($newOutputPhoto instanceof TemporaryUploadedFile) {
                        $outputPhotoPath = $newOutputPhoto->store(
                            'processing/finished-output/'.now()->format('Y/m/d'),
                            'public',
                        );
                        $newPaths[] = $outputPhotoPath;
                        if ($oldOutputDocumentation?->photo_path
                            && $oldOutputDocumentation->photo_path !== $outputPhotoPath) {
                            $oldPaths[] = $oldOutputDocumentation->photo_path;
                        }
                    }

                    $outputQuantity = (float) $documentationData['output_quantity'];
                    $outputUnit = trim((string) $documentationData['output_unit']);
                    $defaultCaption = 'Hasil makanan jadi '
                        .number_format($outputQuantity, 3, ',', '.').' '.$outputUnit;
                    if (count($this->finishedOutputDocumentations) > 1) {
                        $defaultCaption .= ' · Data '.($index + 1);
                    }

                    $outputDocumentation = $batch->documentations()->updateOrCreate(
                        ['id' => $documentationData['documentation_id'] ?: null],
                        [
                            'documentation_type' => 'finished_output',
                            'output_quantity' => $outputQuantity,
                            'output_unit' => $outputUnit,
                            'caption' => $batch->product_name ?: $batch->menu_name_snapshot ?: $defaultCaption,
                            'photo_path' => $outputPhotoPath,
                            'captured_at' => $documentationData['captured_at'] ?: now(),
                            'created_by' => $oldOutputDocumentation?->created_by ?: auth()->id(),
                            'sort_order' => $index + 1,
                        ],
                    );
                    $keptOutputDocumentations[] = $outputDocumentation->id;
                }

                // Dokumentasi lama yang lebih dari satu tidak dihapus otomatis.
                // UI baru hanya mengelola satu dokumentasi utama agar histori lama tetap aman.

                $batch->refresh()->recalculateTotals();
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete(array_values(array_unique($newPaths)));
            throw $exception;
        }
        Storage::disk('public')->delete(array_values(array_unique(array_diff($oldPaths, $newPaths))));

        $this->select($batch->id);
        session()->flash('v3.status', 'Data hasil Pengolahan disimpan.');
    }

    public function start(ProcessingWorkflow $workflow): void
    {
        $batch = $workflow->start($this->record($this->selectedId), auth()->user());
        $this->select($batch->id);
    }

    public function acceptPreparationOutput(int $withdrawalId, PreparationOutputService $service): void
    {
        $withdrawal = PreparationOutputWithdrawal::query()
            ->where('processing_batch_id', $this->selectedId)->findOrFail($withdrawalId);
        $service->verifyWithdrawal($withdrawal, auth()->user(), (float) $withdrawal->requested_quantity);
        $this->select($this->selectedId);
        session()->flash('v3.status', 'Hasil Persiapan diterima ke batch Pengolahan.');
    }

    public function cancel(ProcessingWorkflow $workflow): void
    {
        abort_unless($this->allowed('processing.update'), 403);
        $this->validate([
            'cancellationReason' => ['required', 'string', 'max:2000'],
        ], [], ['cancellationReason' => 'alasan pembatalan']);
        $batch = $workflow->cancel(
            $this->record($this->selectedId),
            auth()->user(),
            $this->cancellationReason,
        );
        $this->cancellationReason = '';
        $this->select($batch->getKey());
        session()->flash('v3.status', 'Produksi berhasil dibatalkan.');
    }

    public function complete(ProcessingPortioningHandoverService $handover): void
    {
        $this->save();
        $batch = $handover->completeAndHandover($this->record($this->selectedId), auth()->user());
        $this->select($batch->id);
        session()->flash(
            'v3.status',
            'Batch selesai, dikunci, dan siap diterima Pemorsian.',
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
                'preparationOutputWithdrawals.output',
                'temperatureLogs',
                'documentations',
                'petugas',
                'portioningSession',
            ])
            ->where('sppg_unit_id', $unit->id)
            ->latest('production_date')
            ->latest('id')
            ->get();
        $selected = $this->selectedId
            ? $records->firstWhere('id', $this->selectedId)
            : null;
        $cancellableBatchIds = $records
            ->filter(fn (ProcessingBatch $batch): bool => app(ProcessingWorkflow::class)->canCancel($batch))
            ->pluck('id')
            ->all();

        return view('livewire.v3.processing.index', [
            ...$this->shellData($unit),
            'records' => $records,
            'selected' => $selected,
            'ingredientSuggestions' => Ingredient::query()
                ->with('measurementUnit')
                ->where('sppg_unit_id', $unit->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(500)
                ->get(),
            'cancellableBatchIds' => $cancellableBatchIds,
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
