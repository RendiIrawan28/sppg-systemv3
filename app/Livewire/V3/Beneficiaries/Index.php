<?php

namespace App\Livewire\V3\Beneficiaries;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\Beneficiary;
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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->status = 'active';
        $this->resetPage();
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

        $query = Beneficiary::query()
            ->with(['category', 'beneficiaryable'])
            ->where('sppg_unit_id', $unit->getKey())
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

        return view('livewire.v3.beneficiaries.index', [
            ...$this->shellData($unit),
            'beneficiaries' => $query->orderBy('name')->paginate(15),
            'activeCount' => Beneficiary::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->where('is_active', true)
                ->count(),
            'totalCount' => Beneficiary::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->count(),
        ])->layout('layouts.v3', ['title' => 'Penerima Manfaat']);
    }
}
