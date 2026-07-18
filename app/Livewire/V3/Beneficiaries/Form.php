<?php

namespace App\Livewire\V3\Beneficiaries;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\Allergen;
use App\Models\Beneficiary;
use App\Models\BeneficiaryCategory;
use App\Models\Posyandu;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    use InteractsWithV3Shell;

    public ?int $beneficiaryId = null;

    public string $beneficiaryableType = '';

    public ?int $beneficiaryableId = null;

    public ?int $beneficiaryCategoryId = null;

    public string $externalId = '';

    public string $name = '';

    public string $groupName = '';

    public string $parentName = '';

    public string $recipientPosition = '';

    public string $birthDate = '';

    public string $gender = '';

    public string $startDate = '';

    public string $endDate = '';

    public string $address = '';

    public string $allergyNotes = '';

    public string $specialNeeds = '';

    public string $notes = '';

    public bool $isActive = true;

    /** @var array<int, array{allergen_id: string|int, severity: string, reaction_notes: string, is_active: bool}> */
    public array $allergens = [];

    public function mount(?Beneficiary $beneficiary = null): void
    {
        $unit = $this->currentUnit();
        $user = auth()->user();

        if ($beneficiary?->exists) {
            abort_unless($user->is_super_admin || $user->can('beneficiaries.update'), 403);
            abort_unless((int) $beneficiary->sppg_unit_id === (int) $unit->getKey(), 404);

            $beneficiary->load('allergenLinks');
            $this->beneficiaryId = $beneficiary->getKey();
            $this->beneficiaryableType = (string) $beneficiary->beneficiaryable_type;
            $this->beneficiaryableId = $beneficiary->beneficiaryable_id;
            $this->beneficiaryCategoryId = $beneficiary->beneficiary_category_id;
            $this->externalId = (string) $beneficiary->external_id;
            $this->name = (string) $beneficiary->name;
            $this->groupName = (string) $beneficiary->group_name;
            $this->parentName = (string) $beneficiary->parent_name;
            $this->recipientPosition = (string) $beneficiary->recipient_position;
            $this->birthDate = $beneficiary->birth_date?->toDateString() ?? '';
            $this->gender = (string) $beneficiary->gender;
            $this->startDate = $beneficiary->start_date?->toDateString() ?? '';
            $this->endDate = $beneficiary->end_date?->toDateString() ?? '';
            $this->address = (string) $beneficiary->address;
            $this->allergyNotes = (string) $beneficiary->allergy_notes;
            $this->specialNeeds = (string) $beneficiary->special_needs;
            $this->notes = (string) $beneficiary->notes;
            $this->isActive = (bool) $beneficiary->is_active;
            $this->allergens = $beneficiary->allergenLinks->map(fn ($link): array => [
                'allergen_id' => $link->allergen_id,
                'severity' => $link->severity ?: 'unknown',
                'reaction_notes' => (string) $link->reaction_notes,
                'is_active' => (bool) $link->is_active,
            ])->values()->all();

            return;
        }

        abort_unless($user->is_super_admin || $user->can('beneficiaries.create'), 403);
        $this->startDate = now()->toDateString();
    }

    public function updatedBeneficiaryableType(): void
    {
        $this->beneficiaryableId = null;
    }

    public function addAllergen(): void
    {
        $this->allergens[] = [
            'allergen_id' => '',
            'severity' => 'unknown',
            'reaction_notes' => '',
            'is_active' => true,
        ];
    }

    public function removeAllergen(int $index): void
    {
        unset($this->allergens[$index]);
        $this->allergens = array_values($this->allergens);
    }

    public function save(): void
    {
        $unit = $this->currentUnit();
        $permission = $this->beneficiaryId ? 'beneficiaries.update' : 'beneficiaries.create';
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can($permission), 403);

        $data = $this->validate($this->rules($unit->getKey()), $this->messages());

        DB::transaction(function () use ($data, $unit): void {
            $beneficiary = $this->beneficiaryId
                ? Beneficiary::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($this->beneficiaryId)
                : new Beneficiary(['sppg_unit_id' => $unit->getKey(), 'data_source' => 'manual']);

            $beneficiary->fill([
                'beneficiaryable_type' => $data['beneficiaryableType'],
                'beneficiaryable_id' => $data['beneficiaryableId'],
                'beneficiary_category_id' => $data['beneficiaryCategoryId'],
                'external_id' => trim($data['externalId']),
                'name' => trim($data['name']),
                'group_name' => $this->nullable($data['groupName']),
                'parent_name' => $this->nullable($data['parentName']),
                'recipient_position' => $this->nullable($data['recipientPosition']),
                'birth_date' => $this->nullable($data['birthDate']),
                'gender' => $this->nullable($data['gender']),
                'start_date' => $this->nullable($data['startDate']),
                'end_date' => $data['isActive'] ? $this->nullable($data['endDate']) : ($this->nullable($data['endDate']) ?: now()->toDateString()),
                'address' => $this->nullable($data['address']),
                'allergy_notes' => $this->nullable($data['allergyNotes']),
                'special_needs' => $this->nullable($data['specialNeeds']),
                'notes' => $this->nullable($data['notes']),
                'is_active' => $data['isActive'],
            ])->save();

            $beneficiary->allergenLinks()->delete();
            foreach ($data['allergens'] as $allergen) {
                $beneficiary->allergenLinks()->create([
                    'allergen_id' => $allergen['allergen_id'],
                    'severity' => $allergen['severity'],
                    'reaction_notes' => $this->nullable($allergen['reaction_notes']),
                    'is_active' => $allergen['is_active'],
                ]);
            }
        });

        session()->flash('v3.status', $this->beneficiaryId ? 'Data penerima berhasil diperbarui.' : 'Penerima baru berhasil ditambahkan.');
        $this->redirectRoute('v3.beneficiaries.index', navigate: true);
    }

    public function delete(): void
    {
        abort_unless($this->beneficiaryId, 404);
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('beneficiaries.delete'), 403);

        DB::transaction(function () use ($unit): void {
            $beneficiary = Beneficiary::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($this->beneficiaryId);
            $beneficiary->allergenLinks()->delete();
            $beneficiary->delete();
        });

        session()->flash('v3.status', 'Data penerima telah dihapus permanen.');
        $this->redirectRoute('v3.beneficiaries.index', navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $institutions = match ($this->beneficiaryableType) {
            'school' => School::query()->where('sppg_unit_id', $unit->getKey())->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'posyandu' => Posyandu::query()->where('sppg_unit_id', $unit->getKey())->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            default => collect(),
        };

        return view('livewire.v3.beneficiaries.form', [
            ...$this->shellData($unit),
            'institutions' => $institutions,
            'categories' => BeneficiaryCategory::query()->where('sppg_unit_id', $unit->getKey())->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'),
            'allergenOptions' => Allergen::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'),
        ])->layout('layouts.v3', ['title' => $this->beneficiaryId ? 'Ubah Penerima' : 'Tambah Penerima']);
    }

    /** @return array<string, mixed> */
    private function rules(int $unitId): array
    {
        $institutionTable = $this->beneficiaryableType === 'posyandu' ? 'posyandus' : 'schools';

        return [
            'beneficiaryableType' => ['required', Rule::in(['school', 'posyandu'])],
            'beneficiaryableId' => ['required', 'integer', Rule::exists($institutionTable, 'id')->where(fn ($query) => $query->where('sppg_unit_id', $unitId))],
            'beneficiaryCategoryId' => ['required', 'integer', Rule::exists('beneficiary_categories', 'id')->where(fn ($query) => $query->where('sppg_unit_id', $unitId)->where('is_active', true))],
            'externalId' => [
                'required', 'string', 'max:100',
                Rule::unique('beneficiaries', 'external_id')
                    ->where(fn ($query) => $query
                        ->where('sppg_unit_id', $unitId)
                        ->where('beneficiaryable_type', $this->beneficiaryableType)
                        ->where('beneficiaryable_id', $this->beneficiaryableId))
                    ->ignore($this->beneficiaryId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'groupName' => ['nullable', 'string', 'max:255'],
            'parentName' => ['nullable', 'string', 'max:255'],
            'recipientPosition' => ['nullable', 'string', 'max:100'],
            'birthDate' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'address' => ['nullable', 'string', 'max:2000'],
            'allergyNotes' => ['nullable', 'string', 'max:2000'],
            'specialNeeds' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'isActive' => ['boolean'],
            'allergens' => ['array'],
            'allergens.*.allergen_id' => ['required', 'integer', 'distinct', Rule::exists('allergens', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'allergens.*.severity' => ['required', Rule::in(['unknown', 'mild', 'moderate', 'severe'])],
            'allergens.*.reaction_notes' => ['nullable', 'string', 'max:1000'],
            'allergens.*.is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'externalId.unique' => 'Nomor induk ini sudah digunakan pada lembaga yang dipilih.',
            'endDate.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'allergens.*.allergen_id.distinct' => 'Alergen yang sama tidak boleh dipilih dua kali.',
        ];
    }

    private function nullable(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
    }
}
