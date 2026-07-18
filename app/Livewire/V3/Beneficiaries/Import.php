<?php

namespace App\Livewire\V3\Beneficiaries;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\BeneficiaryCategory;
use App\Models\Posyandu;
use App\Models\School;
use App\Services\BeneficiaryImportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class Import extends Component
{
    use InteractsWithV3Shell;
    use WithFileUploads;

    public $file;

    public string $institution = '';

    public string $defaultSchoolCategoryCode = '';

    public bool $updateExisting = false;

    /** @var array<string, mixed>|null */
    public ?array $previewResult = null;

    /** @var array<string, mixed>|null */
    public ?array $importResult = null;

    public function updatedInstitution(): void
    {
        $this->reset(['file', 'defaultSchoolCategoryCode', 'previewResult', 'importResult']);
    }

    public function preview(BeneficiaryImportService $service): void
    {
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('beneficiaries.import'), 403);
        $this->validateInput();

        try {
            $this->previewResult = $service->preview(
                file: $this->file,
                institution: $this->resolveInstitution($unit->getKey()),
                defaultSchoolCategoryCode: $this->nullableCode(),
            );
            $this->importResult = null;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('file', $exception->getMessage());
        }
    }

    public function import(BeneficiaryImportService $service): void
    {
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('beneficiaries.import'), 403);
        $this->validateInput();

        if (! $this->previewResult) {
            $this->addError('file', 'Periksa file terlebih dahulu sebelum menjalankan impor.');

            return;
        }

        try {
            $result = $service->import(
                file: $this->file,
                institution: $this->resolveInstitution($unit->getKey()),
                user: auth()->user(),
                updateExisting: $this->updateExisting,
                defaultSchoolCategoryCode: $this->nullableCode(),
            );

            $this->importResult = [
                'status' => $result->status,
                'total' => $result->total_rows,
                'inserted' => $result->imported_rows,
                'updated' => $result->updated_rows,
                'invalid' => $result->invalid_rows,
                'errors' => $result->errors ?? [],
            ];
            $this->reset(['file', 'previewResult']);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('file', $exception->getMessage());
        }
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('beneficiaries.import'), 403);

        return view('livewire.v3.beneficiaries.import', [
            ...$this->shellData($unit),
            'schools' => School::query()->where('sppg_unit_id', $unit->getKey())->where('is_active', true)->orderBy('name')->get(),
            'posyandus' => Posyandu::query()->where('sppg_unit_id', $unit->getKey())->where('is_active', true)->orderBy('name')->get(),
            'schoolCategories' => BeneficiaryCategory::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->where('is_active', true)
                ->where('group_type', 'school')
                ->orderBy('sort_order')
                ->get(),
        ])->layout('layouts.v3', ['title' => 'Impor Penerima']);
    }

    private function validateInput(): void
    {
        $unit = $this->currentUnit();
        $this->validate([
            'institution' => ['required', 'regex:/^(school|posyandu):[0-9]+$/'],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
            'defaultSchoolCategoryCode' => [
                'nullable', 'string', 'max:100',
                Rule::exists('beneficiary_categories', 'code')->where(fn ($query) => $query->where('sppg_unit_id', $unit->getKey())->where('is_active', true)),
            ],
            'updateExisting' => ['boolean'],
        ], [
            'institution.required' => 'Pilih sekolah atau posyandu tujuan impor.',
            'file.required' => 'Pilih file Excel atau CSV terlebih dahulu.',
            'file.mimes' => 'Format file harus XLSX, XLS, atau CSV.',
            'file.max' => 'Ukuran file maksimal 5 MB.',
        ]);
    }

    private function resolveInstitution(int $unitId): School|Posyandu
    {
        [$type, $id] = array_pad(explode(':', $this->institution, 2), 2, null);
        $model = match ($type) {
            'school' => School::class,
            'posyandu' => Posyandu::class,
            default => null,
        };

        abort_unless($model && is_subclass_of($model, Model::class), 422, 'Jenis lembaga tidak valid.');

        return $model::query()->where('sppg_unit_id', $unitId)->where('is_active', true)->findOrFail((int) $id);
    }

    private function nullableCode(): ?string
    {
        return trim($this->defaultSchoolCategoryCode) !== '' ? trim($this->defaultSchoolCategoryCode) : null;
    }
}
