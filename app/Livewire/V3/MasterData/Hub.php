<?php

namespace App\Livewire\V3\MasterData;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\User;
use App\Support\V3\MasterDataRegistry;
use Livewire\Component;

class Hub extends Component
{
    use InteractsWithV3Shell;

    public function mount(): void
    {
        $this->currentUnit();
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $catalogs = collect(app(MasterDataRegistry::class)->all())
            ->filter(function (array $config): bool {
                $permission = $config['permission'];

                return $this->allowed("{$permission}.view") || $this->allowed("{$permission}.manage");
            })
            ->map(function (array $config, string $slug) use ($unit): array {
                $query = $config['model']::query();
                if ($config['unitScoped']) {
                    $query->where('sppg_unit_id', $unit->getKey());
                }

                return [...$config, 'slug' => $slug, 'count' => $query->count()];
            })->values();

        return view('livewire.v3.master-data.hub', [
            ...$this->shellData($unit), 'catalogs' => $catalogs,
            'userCount' => $this->allowed('users.view') ? User::query()->count() : null,
        ])->layout('layouts.v3', ['title' => 'Master Data']);
    }
}
