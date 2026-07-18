<?php

namespace App\Livewire\V3\MasterData;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\Allergen;
use App\Models\NutritionComponent;
use App\Support\V3\MasterDataRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

class Catalog extends Component
{
    use InteractsWithV3Shell;
    use WithFileUploads;
    use WithPagination;

    public string $catalog;

    public ?int $recordId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<int, array<string, mixed>> */
    public array $nutritionRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $allergenRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $checklistRows = [];

    public $ingredientPhoto = null;

    public ?string $existingPhotoPath = null;

    public ?string $actionMessage = null;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'all';

    public function mount(string $catalog): void
    {
        $this->catalog = $catalog;
        $this->currentUnit();
        $this->authorizeView();
        $this->resetForm();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorizeCreate();
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $this->authorizeUpdate();
        $record = $this->findRecord($id);
        $config = $this->config();
        $this->recordId = $record->getKey();
        $this->form = collect($config['fields'])->mapWithKeys(function (array $field, string $name) use ($record): array {
            $value = $record->{$name};
            if ($field['type'] === 'toggle') {
                $value = (bool) $value;
            }
            if ($field['type'] === 'date' && $value) {
                $value = $value->toDateString();
            }

            return [$name => $value ?? ''];
        })->all();

        if ($config['special'] === 'ingredient') {
            $record->load(['nutritions', 'allergenLinks']);
            $this->existingPhotoPath = $record->photo_path;
            $this->nutritionRows = $record->nutritions->map(fn ($item): array => [
                'nutrition_component_id' => $item->nutrition_component_id,
                'value_per_100g' => (float) $item->value_per_100g,
                'source' => (string) $item->source,
                'notes' => (string) $item->notes,
            ])->values()->all();
            $this->allergenRows = $record->allergenLinks->map(fn ($item): array => [
                'allergen_id' => $item->allergen_id,
                'contamination_risk' => (bool) $item->contamination_risk,
                'notes' => (string) $item->notes,
            ])->values()->all();
        }

        if ($config['special'] === 'cleaning') {
            $this->checklistRows = array_values($record->default_checklist ?? []);
        }

        $this->actionMessage = null;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function addNutrition(): void
    {
        $this->nutritionRows[] = ['nutrition_component_id' => '', 'value_per_100g' => '', 'source' => '', 'notes' => ''];
    }

    public function removeNutrition(int $index): void
    {
        unset($this->nutritionRows[$index]);
        $this->nutritionRows = array_values($this->nutritionRows);
    }

    public function addAllergen(): void
    {
        $this->allergenRows[] = ['allergen_id' => '', 'contamination_risk' => false, 'notes' => ''];
    }

    public function removeAllergen(int $index): void
    {
        unset($this->allergenRows[$index]);
        $this->allergenRows = array_values($this->allergenRows);
    }

    public function addChecklist(): void
    {
        $this->checklistRows[] = ['category' => 'surface', 'item_name' => '', 'is_mandatory' => true];
    }

    public function removeChecklist(int $index): void
    {
        unset($this->checklistRows[$index]);
        $this->checklistRows = array_values($this->checklistRows);
    }

    public function save(): void
    {
        $this->recordId ? $this->authorizeUpdate() : $this->authorizeCreate();
        $config = $this->config();
        $unit = $this->currentUnit();
        $rules = [];

        foreach ($config['fields'] as $name => $field) {
            $fieldRules = $field['rules'];
            if ($field['unique']) {
                $model = new $config['model'];
                $unique = Rule::unique($model->getTable(), $name)->ignore($this->recordId);
                if ($config['unitScoped']) {
                    $unique->where('sppg_unit_id', $unit->getKey());
                }
                $fieldRules[] = $unique;
            }
            $rules["form.{$name}"] = $fieldRules;
        }

        if ($config['special'] === 'ingredient') {
            $rules += [
                'ingredientPhoto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
                'nutritionRows' => ['array'], 'nutritionRows.*.nutrition_component_id' => ['required', 'integer', 'distinct', 'exists:nutrition_components,id'], 'nutritionRows.*.value_per_100g' => ['required', 'numeric', 'min:0'], 'nutritionRows.*.source' => ['nullable', 'string', 'max:255'], 'nutritionRows.*.notes' => ['nullable', 'string', 'max:1000'],
                'allergenRows' => ['array'], 'allergenRows.*.allergen_id' => ['required', 'integer', 'distinct', 'exists:allergens,id'], 'allergenRows.*.contamination_risk' => ['boolean'], 'allergenRows.*.notes' => ['nullable', 'string', 'max:1000'],
            ];
        }
        if ($config['special'] === 'cleaning') {
            $rules += ['checklistRows' => ['array'], 'checklistRows.*.category' => ['required', Rule::in(['preparation', 'surface', 'floor', 'equipment', 'waste', 'personal_hygiene', 'other'])], 'checklistRows.*.item_name' => ['required', 'string', 'max:255'], 'checklistRows.*.is_mandatory' => ['boolean']];
        }

        $validated = $this->validate($rules);
        $payload = [];
        foreach ($config['fields'] as $name => $field) {
            $value = $validated['form'][$name] ?? null;
            if ($field['normalize']) {
                $value = app(MasterDataRegistry::class)->normalize($field['normalize'], $value);
            }
            if ($field['type'] === 'toggle') {
                $value = (bool) $value;
            } elseif ($value === '') {
                $value = null;
            }
            $payload[$name] = $value;
        }
        if ($config['unitScoped']) {
            $payload['sppg_unit_id'] = $unit->getKey();
        }
        if ($config['special'] === 'cleaning') {
            $payload['default_checklist'] = $validated['checklistRows'];
            $payload[$this->recordId ? 'updated_by' : 'created_by'] = auth()->id();
        }

        try {
            DB::transaction(function () use ($config, $payload, $validated): void {
                $modelClass = $config['model'];
                $record = $this->recordId ? $this->findRecord($this->recordId) : new $modelClass;
                $oldPhoto = $record->photo_path ?? null;

                if ($config['special'] === 'ingredient' && $this->ingredientPhoto) {
                    $payload['photo_path'] = $this->ingredientPhoto->store('ingredients', 'public');
                }
                $record->fill($payload)->save();

                if ($config['special'] === 'ingredient') {
                    $record->nutritions()->delete();
                    foreach ($validated['nutritionRows'] as $row) {
                        $record->nutritions()->create($this->cleanRelationRow($row));
                    }
                    $record->allergenLinks()->delete();
                    foreach ($validated['allergenRows'] as $row) {
                        $record->allergenLinks()->create($this->cleanRelationRow($row));
                    }
                    if ($this->ingredientPhoto && $oldPhoto && $oldPhoto !== $payload['photo_path']) {
                        Storage::disk('public')->delete($oldPhoto);
                    }
                }
            });
            $this->actionMessage = ($this->recordId ? 'Perubahan ' : 'Data ').strtolower($config['label']).' berhasil disimpan.';
            $this->resetForm(keepMessage: true);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('action', $exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : $exception->getMessage());
        }
    }

    public function delete(int $id): void
    {
        $this->authorizeDelete();
        try {
            $this->findRecord($id)->delete();
            $this->actionMessage = 'Data berhasil dihapus.';
            if ($this->recordId === $id) {
                $this->resetForm(keepMessage: true);
            }
        } catch (QueryException) {
            $this->addError('action', 'Data masih digunakan oleh proses lain. Nonaktifkan data ini sebagai pengganti penghapusan.');
        }
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $this->authorizeView();
        $config = $this->config();
        $query = $this->baseQuery();
        $searchFields = array_values(array_filter($config['listFields'], fn (string $field): bool => ! str_ends_with($field, '_id')));
        $query->when(trim($this->search) !== '', function ($query) use ($searchFields): void {
            $search = trim($this->search);
            $query->where(fn ($query) => collect($searchFields)->each(fn (string $field) => $query->orWhere($field, 'like', "%{$search}%")));
        });
        if (array_key_exists('is_active', $config['fields']) && $this->status !== 'all') {
            $query->where('is_active', $this->status === 'active');
        }
        $fieldOptions = collect($config['fields'])->mapWithKeys(fn (array $field, string $name): array => [$name => $field['source'] ? app(MasterDataRegistry::class)->options($field['source'], $unit->getKey()) : $field['options']])->all();

        return view('livewire.v3.master-data.catalog', [
            ...$this->shellData($unit), 'config' => $config, 'records' => $query->latest('updated_at')->paginate(15), 'fieldOptions' => $fieldOptions,
            'canCreate' => $this->can('create'), 'canUpdate' => $this->can('update'), 'canDelete' => $this->can('delete'),
            'nutritionComponents' => NutritionComponent::query()->where('is_active', true)->orderBy('sort_order')->get(), 'allergens' => Allergen::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ])->layout('layouts.v3', ['title' => $config['label']]);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return app(MasterDataRegistry::class)->get($this->catalog);
    }

    private function baseQuery()
    {
        $config = $this->config();
        $query = $config['model']::query();

        return $config['unitScoped'] ? $query->where('sppg_unit_id', $this->currentUnit()->getKey()) : $query;
    }

    private function findRecord(int $id): Model
    {
        return $this->baseQuery()->findOrFail($id);
    }

    private function resetForm(bool $keepMessage = false): void
    {
        $config = $this->config();
        $this->recordId = null;
        $this->form = collect($config['fields'])->mapWithKeys(fn (array $field, string $name): array => [$name => $field['default'] === 'today' ? now()->toDateString() : $field['default']])->all();
        $this->nutritionRows = [];
        $this->allergenRows = [];
        $this->checklistRows = [];
        $this->ingredientPhoto = null;
        $this->existingPhotoPath = null;
        if (! $keepMessage) {
            $this->actionMessage = null;
        }
        $this->resetErrorBag();
    }

    /** @return array<string, mixed> */
    private function cleanRelationRow(array $row): array
    {
        return collect($row)->map(fn ($value) => $value === '' ? null : $value)->all();
    }

    private function can(string $action): bool
    {
        $permission = $this->config()['permission'];
        if ($this->allowed("{$permission}.manage")) {
            return true;
        }

        return $this->allowed("{$permission}.{$action}");
    }

    private function authorizeView(): void
    {
        abort_unless($this->can('view'), 403);
    }

    private function authorizeCreate(): void
    {
        abort_unless($this->can('create'), 403);
    }

    private function authorizeUpdate(): void
    {
        abort_unless($this->can('update'), 403);
    }

    private function authorizeDelete(): void
    {
        abort_unless($this->can('delete'), 403);
    }
}
