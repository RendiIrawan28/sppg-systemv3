<?php

namespace App\Livewire\V3\Processing;

use App\Enums\OperationalReportStatus;
use App\Enums\ProcessingBatchState;
use App\Enums\ProcessingTemperatureCheckpoint;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\ProcessingBatch;
use App\Models\ProcessingDocumentation;
use App\Services\ProcessingReturnService;
use App\Services\ProcessingWorkflow;
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
    }

    public function select(int $id): void
    {
        $batch = $this->record($id)->load([
            'temperatureLogs',
            'documentations',
        ]);
        $this->selectedId = $id;
        $this->notes = (string) $batch->notes;
        $this->reviewNotes = (string) $batch->review_notes;
        $this->finishedOutputDocumentations = $batch->documentations
            ->where('documentation_type', 'finished_output')
            ->values()
            ->map(function (ProcessingDocumentation $documentation): array {
                return [
                    'documentation_id' => $documentation->id,
                    'output_quantity' => $documentation->output_quantity !== null
                        ? (string) $documentation->output_quantity
                        : '',
                    'output_unit' => (string) $documentation->output_unit,
                    'caption' => $documentation->caption,
                    'captured_at' => $documentation->captured_at?->format('Y-m-d\TH:i')
                        ?: now()->format('Y-m-d\TH:i'),
                    'photo_path' => $documentation->photo_path,
                ];
            })
            ->all();
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
        if ($batch->state === ProcessingBatchState::InProgress && $this->cookedProducts === []) {
            $this->addCookedProduct();
        }
        if ($batch->state === ProcessingBatchState::InProgress && $this->finishedOutputDocumentations === []) {
            $this->addFinishedOutputDocumentation();
        }
    }

    public function addCookedProduct(): void
    {
        $this->cookedProducts[] = [
            'temperature_id' => null,
            'product_name' => '',
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
        $this->finishedOutputDocumentations[] = [
            'documentation_id' => null,
            'output_quantity' => '',
            'output_unit' => '',
            'caption' => '',
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

        $this->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'cookedProducts' => ['required', 'array', 'min:1'],
            'cookedProducts.*.product_name' => ['required', 'string', 'max:255'],
            'cookedProducts.*.temperature_celsius' => ['required', 'numeric', 'between:-30,150'],
            'cookedProducts.*.cooked_at' => ['required', 'date'],
            'cookedProducts.*.notes' => ['nullable', 'string', 'max:1000'],
            'temperaturePhotos.*' => ['nullable', 'image', 'max:5120'],
            'finishedOutputDocumentations' => ['required', 'array', 'min:1'],
            'finishedOutputDocumentations.*.output_quantity' => ['required', 'numeric', 'gt:0'],
            'finishedOutputDocumentations.*.output_unit' => ['required', 'string', 'max:80'],
            'finishedOutputDocumentations.*.caption' => ['nullable', 'string', 'max:255'],
            'finishedOutputDocumentations.*.captured_at' => ['required', 'date'],
            'finishedOutputPhotos.*' => ['nullable', 'image', 'max:5120'],
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
                            'product_name' => trim($product['product_name']),
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
                            'caption' => filled($documentationData['caption'] ?? null)
                                ? trim($documentationData['caption'])
                                : $defaultCaption,
                            'photo_path' => $outputPhotoPath,
                            'captured_at' => $documentationData['captured_at'],
                            'created_by' => $oldOutputDocumentation?->created_by ?: auth()->id(),
                            'sort_order' => $index + 1,
                        ],
                    );
                    $keptOutputDocumentations[] = $outputDocumentation->id;
                }

                $batch->documentations()
                    ->where('documentation_type', 'finished_output')
                    ->whereNotIn('id', $keptOutputDocumentations)
                    ->get()
                    ->each(function (ProcessingDocumentation $documentation) use (&$oldPaths): void {
                        if ($documentation->photo_path) {
                            $oldPaths[] = $documentation->photo_path;
                        }
                        $documentation->delete();
                    });
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

    public function complete(ProcessingWorkflow $workflow): void
    {
        $this->save();
        $batch = $workflow->complete($this->record($this->selectedId), auth()->user());
        $this->select($batch->id);
        session()->flash(
            'v3.status',
            'Pengolahan selesai. Data suhu dan hasil akhir telah tersimpan.',
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
