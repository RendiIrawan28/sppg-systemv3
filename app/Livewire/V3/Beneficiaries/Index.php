<?php

namespace App\Livewire\V3\Beneficiaries;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\Beneficiary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'active';

    /** @var array<int, string|int> */
    public array $selectedBeneficiaryIds = [];

    public function updatedSearch(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->clearSelection();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->status = 'active';
        $this->clearSelection();
        $this->resetPage();
    }

    public function clearSelection(): void
    {
        $this->selectedBeneficiaryIds = [];
        $this->resetErrorBag('selectedBeneficiaryIds');
    }

    public function togglePageSelection(): void
    {
        abort_unless($this->allowed('beneficiaries.delete'), 403);
        $pageIds = $this->filteredQuery($this->currentUnit()->getKey())
            ->orderBy('name')->orderBy('id')->paginate(15, ['id'])->getCollection()
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
        $selected = array_map('strval', $this->selectedBeneficiaryIds);
        $allSelected = $pageIds !== [] && count(array_intersect($pageIds, $selected)) === count($pageIds);
        $this->selectedBeneficiaryIds = $allSelected
            ? array_values(array_diff($selected, $pageIds))
            : array_values(array_unique([...$selected, ...$pageIds]));
        $this->resetErrorBag('selectedBeneficiaryIds');
    }

    public function deleteSelected(): void
    {
        abort_unless($this->allowed('beneficiaries.delete'), 403);
        $ids = $this->validate([
            'selectedBeneficiaryIds' => ['required', 'array', 'min:1', 'max:500'],
            'selectedBeneficiaryIds.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], [
            'selectedBeneficiaryIds.required' => 'Pilih setidaknya satu penerima yang ingin dihapus.',
            'selectedBeneficiaryIds.min' => 'Pilih setidaknya satu penerima yang ingin dihapus.',
            'selectedBeneficiaryIds.max' => 'Maksimal 500 penerima dalam satu penghapusan.',
            'selectedBeneficiaryIds.*.integer' => 'Pilihan penerima tidak valid. Pilih ulang datanya.',
            'selectedBeneficiaryIds.*.distinct' => 'Ada pilihan penerima yang ganda. Pilih ulang datanya.',
        ])['selectedBeneficiaryIds'];
        $unitId = $this->currentUnit()->getKey();

        DB::transaction(function () use ($ids, $unitId): void {
            $beneficiaries = Beneficiary::query()->where('sppg_unit_id', $unitId)
                ->whereIn('id', $ids)->lockForUpdate()->get();
            if ($beneficiaries->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'selectedBeneficiaryIds' => 'Sebagian penerima sudah berubah atau tidak tersedia. Pilih ulang datanya.',
                ]);
            }

            foreach ($beneficiaries as $beneficiary) {
                $beneficiary->allergenLinks()->delete();
                $beneficiary->delete();
            }
        });

        $count = count($ids);
        $this->clearSelection();
        $this->resetPage();
        session()->flash('v3.status', "{$count} data penerima berhasil dihapus permanen.");
    }

    public function toggleStatus(int $beneficiaryId): void
    {
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('beneficiaries.update'), 403);

        $beneficiary = Beneficiary::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($beneficiaryId);
        $activate = ! $beneficiary->is_active;
        $beneficiary->update([
            'is_active' => $activate,
            'end_date' => $activate ? null : now()->toDateString(),
        ]);

        session()->flash('v3.status', $activate ? 'Penerima berhasil diaktifkan.' : 'Penerima berhasil dinonaktifkan.');
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('beneficiaries.view'), 403);

        $query = $this->filteredQuery($unit->getKey())
            ->with(['category', 'beneficiaryable']);

        return view('livewire.v3.beneficiaries.index', [
            ...$this->shellData($unit),
            'beneficiaries' => $query->orderBy('name')->orderBy('id')->paginate(15),
            'activeCount' => Beneficiary::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->where('is_active', true)
                ->count(),
            'totalCount' => Beneficiary::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->count(),
        ])->layout('layouts.v3', ['title' => 'Penerima Manfaat']);
    }

    private function filteredQuery(int $unitId): Builder
    {
        return Beneficiary::query()
            ->where('sppg_unit_id', $unitId)
            ->when($this->search !== '', function ($query): void {
                $search = trim($this->search);
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('external_id', 'like', "%{$search}%")
                        ->orWhere('group_name', 'like', "%{$search}%");
                });
            })
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('is_active', false));
    }
}
