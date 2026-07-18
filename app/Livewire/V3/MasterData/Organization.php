<?php

namespace App\Livewire\V3\MasterData;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Organization extends Component
{
    use InteractsWithV3Shell;

    public string $code = '';

    public string $name = '';

    public string $headName = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public ?string $actionMessage = null;

    public function mount(): void
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('units.view') || $this->allowed('settings.manage'), 403);
        $this->code = $unit->code;
        $this->name = $unit->name;
        $this->headName = (string) $unit->head_name;
        $this->phone = (string) $unit->phone;
        $this->email = (string) $unit->email;
        $this->address = (string) $unit->address;
    }

    public function save(): void
    {
        abort_unless($this->allowed('units.update') || $this->allowed('settings.manage'), 403);
        $unit = $this->currentUnit();
        $data = $this->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('sppg_units', 'code')->ignore($unit->id)], 'name' => ['required', 'string', 'max:255'], 'headName' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'],
        ]);
        $unit->update(['code' => str($data['code'])->trim()->upper(), 'name' => trim($data['name']), 'head_name' => trim($data['headName']) ?: null, 'phone' => trim($data['phone']) ?: null, 'email' => trim($data['email']) ?: null, 'address' => trim($data['address']) ?: null]);
        $this->actionMessage = 'Profil organisasi berhasil diperbarui.';
    }

    public function render()
    {
        $unit = $this->currentUnit();

        return view('livewire.v3.master-data.organization', [...$this->shellData($unit), 'canEdit' => $this->allowed('units.update') || $this->allowed('settings.manage')])->layout('layouts.v3', ['title' => 'Profil Organisasi']);
    }
}
