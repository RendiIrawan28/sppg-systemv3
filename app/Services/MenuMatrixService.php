<?php

namespace App\Services;

use App\Enums\MenuComponentType;
use App\Enums\MenuStatus;
use App\Models\Menu;
use App\Models\MenuCycle;
use App\Models\MenuCycleDay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MenuMatrixService
{
    /** @return array<int, array<string, mixed>> */
    public function rows(MenuCycle $cycle, User $actor): array
    {
        $cycle->load(['days' => fn ($query) => $query->with('menu.items')->orderBy('day_number')]);

        return $cycle->days->mapWithKeys(function (MenuCycleDay $day) use ($cycle, $actor): array {
            $menu = $day->menu;

            return [(int) $day->getKey() => [
                'day_id' => (int) $day->getKey(),
                'day_number' => (int) $day->day_number,
                'service_date' => $day->service_date?->translatedFormat('d M Y') ?? '—',
                'menu_id' => $menu?->getKey(),
                'selected_menu_id' => $menu?->getKey(),
                'menu_name' => $menu?->name ?? '',
                'staple' => $this->componentValue($menu, MenuComponentType::Staple),
                'animal_protein' => $this->componentValue($menu, MenuComponentType::AnimalProtein),
                'plant_protein' => $this->componentValue($menu, MenuComponentType::PlantProtein),
                'milk' => $this->componentValue($menu, MenuComponentType::Milk),
                'vegetables' => $this->componentValue($menu, MenuComponentType::Vegetable, "\n"),
                'fruit' => $this->componentValue($menu, MenuComponentType::Fruit),
                'notes' => $menu?->notes ?? '',
                'status_label' => $menu?->status?->label() ?? 'Belum ada menu',
                'can_edit' => ($actor->is_super_admin || $actor->can('menus.update'))
                    && $cycle->isEditable()
                    && ($menu === null || $menu->isEditable()),
                'can_assign' => ($actor->is_super_admin || $actor->can('menus.update')) && $cycle->isEditable(),
            ]];
        })->all();
    }

    /** @param array<string, mixed> $row */
    public function saveRow(MenuCycle $cycle, int $dayId, array $row, User $actor): Menu
    {
        $this->authorize($actor, 'menus.update');
        $day = $this->day($cycle, $dayId);

        if (! $cycle->isEditable()) {
            throw ValidationException::withMessages(['cycle' => 'Siklus sudah diajukan atau dikunci.']);
        }

        $data = Validator::make($row, [
            'menu_name' => ['required', 'string', 'max:255'],
            'staple' => ['required', 'string', 'max:255'],
            'animal_protein' => ['required', 'string', 'max:255'],
            'plant_protein' => ['nullable', 'string', 'max:255', 'required_without:milk'],
            'milk' => ['nullable', 'string', 'max:255', 'required_without:plant_protein'],
            'vegetables' => ['required', 'string', 'max:2000'],
            'fruit' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'menu_name.required' => "Nama menu hari ke-{$day->day_number} wajib diisi.",
            'staple.required' => "Karbohidrat hari ke-{$day->day_number} wajib diisi.",
            'animal_protein.required' => "Protein hewani hari ke-{$day->day_number} wajib diisi.",
            'plant_protein.required_without' => "Protein nabati atau susu hari ke-{$day->day_number} wajib diisi minimal salah satu.",
            'milk.required_without' => "Protein nabati atau susu hari ke-{$day->day_number} wajib diisi minimal salah satu.",
            'vegetables.required' => "Minimal satu sayur hari ke-{$day->day_number} wajib diisi.",
            'fruit.required' => "Buah hari ke-{$day->day_number} wajib diisi.",
        ])->validate();

        return DB::transaction(function () use ($cycle, $day, $data, $actor): Menu {
            $menu = $day->menu;

            if (! $menu) {
                $menu = Menu::query()->create([
                    'sppg_unit_id' => $cycle->sppg_unit_id,
                    'code' => $this->newMenuCode($day),
                    'name' => trim($data['menu_name']),
                    'service_date' => $day->service_date,
                    'meal_type' => 'lunch',
                    'planned_portions' => $cycle->bufferedTotalPortions(),
                    'is_cycle_snapshot' => false,
                    'snapshot_version' => 0,
                    'status' => MenuStatus::Draft,
                    'revision_number' => 0,
                    'created_by' => $actor->getKey(),
                ]);
                $day->update(['menu_id' => $menu->getKey()]);
            }

            if (! $menu->isEditable()) {
                throw ValidationException::withMessages(['menu' => "Menu {$menu->code} sudah dikunci."]);
            }

            $shared = $menu->cycleDays()->whereKeyNot($day->getKey())->exists();
            $menu->update([
                'name' => trim($data['menu_name']),
                'service_date' => $shared ? null : $day->service_date,
                'planned_portions' => $cycle->bufferedTotalPortions(),
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            ]);

            $this->syncCategoryTargets($menu, $cycle);
            $this->syncOne($menu, MenuComponentType::Staple, $data['staple'], 10, true);
            $this->syncOne($menu, MenuComponentType::AnimalProtein, $data['animal_protein'], 20, true);
            $this->syncOne($menu, MenuComponentType::PlantProtein, $data['plant_protein'] ?? null, 30, false);
            $this->syncOne($menu, MenuComponentType::Milk, $data['milk'] ?? null, 40, false);
            $this->syncMany($menu, MenuComponentType::Vegetable, $data['vegetables'], 50);
            $this->syncOne($menu, MenuComponentType::Fruit, $data['fruit'], 60, true);

            return $menu->refresh();
        });
    }

    public function assignExisting(MenuCycle $cycle, int $dayId, int $menuId, User $actor): void
    {
        $this->authorize($actor, 'menus.update');

        if (! $cycle->isEditable()) {
            throw ValidationException::withMessages(['cycle' => 'Siklus sudah dikunci.']);
        }

        $day = $this->day($cycle, $dayId);
        $menu = Menu::query()
            ->where('sppg_unit_id', $cycle->sppg_unit_id)
            ->where('is_cycle_snapshot', false)
            ->whereKey($menuId)
            ->firstOrFail();

        $day->update(['menu_id' => $menu->getKey(), 'source_menu_id' => null, 'snapshot_version' => 0, 'snapshot_created_at' => null]);
        $menu->update(['planned_portions' => $cycle->bufferedTotalPortions()]);
        $this->syncCategoryTargets($menu, $cycle);

        if ($menu->isEditable() && $menu->cycleDays()->count() > 1) {
            $menu->update(['service_date' => null]);
        }
    }

    public function copyToNextDay(MenuCycle $cycle, int $dayId, User $actor): void
    {
        $this->authorize($actor, 'menus.update');
        $day = $this->day($cycle, $dayId);

        if (! $cycle->isEditable() || ! $day->menu_id) {
            throw ValidationException::withMessages(['menu' => 'Simpan menu pada siklus yang masih editable terlebih dahulu.']);
        }

        $next = $cycle->days()->where('day_number', '>', $day->day_number)->orderBy('day_number')->first();
        if (! $next) {
            throw ValidationException::withMessages(['day' => 'Tidak ada hari berikutnya dalam siklus.']);
        }

        $next->update(['menu_id' => $day->menu_id, 'source_menu_id' => null, 'snapshot_version' => 0, 'snapshot_created_at' => null]);
        $day->menu?->update(['service_date' => null]);
    }

    public function duplicate(MenuCycle $cycle, int $dayId, User $actor): void
    {
        $this->authorize($actor, 'menus.update');
        $day = $this->day($cycle, $dayId);

        if (! $cycle->isEditable() || ! $day->menu) {
            throw ValidationException::withMessages(['menu' => 'Menu belum tersedia atau siklus sudah dikunci.']);
        }

        $clone = app(MenuCloneService::class)->cloneAsIndependentDraft($day->menu, $day, $actor);
        $day->update(['menu_id' => $clone->getKey(), 'source_menu_id' => null, 'snapshot_version' => 0, 'snapshot_created_at' => null]);
    }

    public function detach(MenuCycle $cycle, int $dayId, User $actor): void
    {
        $this->authorize($actor, 'menus.update');
        if (! $cycle->isEditable()) {
            throw ValidationException::withMessages(['cycle' => 'Siklus sudah dikunci.']);
        }

        $this->day($cycle, $dayId)->update(['menu_id' => null, 'source_menu_id' => null, 'snapshot_version' => 0, 'snapshot_created_at' => null]);
    }

    private function syncOne(Menu $menu, MenuComponentType $type, ?string $name, int $sortOrder, bool $required): void
    {
        $name = trim((string) $name);
        $items = $menu->items()->where('item_type', $type->value)->withCount('recipeIngredients')->get();
        $candidate = $items->first(fn ($item): bool => ($item->getRawOriginal('menu_audience') ?? 'all') === 'all');
        $candidate ??= $items->count() === 1 ? $items->first() : null;

        if ($name === '') {
            if ($required) {
                throw ValidationException::withMessages([$type->value => "Komponen {$type->label()} wajib diisi."]);
            }
            if ($candidate && (int) $candidate->recipe_ingredients_count === 0) {
                $candidate->delete();
            }

            return;
        }

        if (! $candidate && $items->count() > 1) {
            throw ValidationException::withMessages([$type->value => "Komponen {$type->label()} memiliki beberapa varian. Ubah melalui editor resep."]);
        }

        if ($candidate) {
            $candidate->update(['name' => $name, 'sort_order' => $sortOrder]);

            return;
        }

        $menu->items()->create(['name' => $name, 'item_type' => $type->value, 'menu_audience' => 'all', 'portion_size' => 'all', 'sort_order' => $sortOrder]);
    }

    private function syncMany(Menu $menu, MenuComponentType $type, string $rawNames, int $sortStart): void
    {
        $names = collect(preg_split('/\r\n|\r|\n|\s*\|\s*|\s*;\s*/', trim($rawNames)) ?: [])
            ->map(fn (string $name): string => trim($name))->filter()->unique(fn (string $name): string => mb_strtolower($name))->values();

        if ($names->isEmpty()) {
            throw ValidationException::withMessages([$type->value => "Minimal satu komponen {$type->label()} wajib diisi."]);
        }

        $items = $menu->items()->where('item_type', $type->value)->where('menu_audience', 'all')->withCount('recipeIngredients')->orderBy('sort_order')->get();
        $used = [];

        foreach ($names as $index => $name) {
            $candidate = $items->first(fn ($item): bool => ! in_array((int) $item->getKey(), $used, true) && mb_strtolower(trim((string) $item->name)) === mb_strtolower($name));
            $candidate ??= $items->first(fn ($item): bool => ! in_array((int) $item->getKey(), $used, true) && (int) $item->recipe_ingredients_count === 0);

            if ($candidate) {
                $candidate->update(['name' => $name, 'sort_order' => $sortStart + $index]);
                $used[] = (int) $candidate->getKey();
            } else {
                $used[] = (int) $menu->items()->create(['name' => $name, 'item_type' => $type->value, 'menu_audience' => 'all', 'portion_size' => 'all', 'sort_order' => $sortStart + $index])->getKey();
            }
        }

        foreach ($items->whereNotIn('id', $used) as $unused) {
            if ((int) $unused->recipe_ingredients_count > 0) {
                throw ValidationException::withMessages([$type->value => "Komponen {$unused->name} masih memiliki bahan resep. Ubah melalui editor resep."]);
            }
            $unused->delete();
        }
    }

    private function syncCategoryTargets(Menu $menu, MenuCycle $cycle): void
    {
        if (! $cycle->beneficiary_period_id) {
            return;
        }

        $categoryIds = DB::table('beneficiary_period_members')->where('beneficiary_period_id', $cycle->beneficiary_period_id)
            ->where('is_active', true)->whereNotNull('beneficiary_category_id')->distinct()->pluck('beneficiary_category_id');

        foreach ($categoryIds as $categoryId) {
            $menu->categoryTargets()->firstOrCreate(['beneficiary_category_id' => $categoryId], ['portion_multiplier' => 1]);
        }
    }

    private function componentValue(?Menu $menu, MenuComponentType $type, string $separator = ' / '): string
    {
        return $menu ? $menu->items->where('item_type', $type->value)->pluck('name')->filter()->unique()->implode($separator) : '';
    }

    private function day(MenuCycle $cycle, int $dayId): MenuCycleDay
    {
        return MenuCycleDay::query()->with('menu')->where('menu_cycle_id', $cycle->getKey())->findOrFail($dayId);
    }

    private function newMenuCode(MenuCycleDay $day): string
    {
        $base = 'MENU-'.($day->service_date?->format('Ymd') ?? now()->format('Ymd'))."-D{$day->day_number}";
        $candidate = $base;
        $counter = 2;

        while (Menu::query()->where('sppg_unit_id', $day->cycle->sppg_unit_id)->where('code', $candidate)->exists()) {
            $candidate = mb_substr($base, 0, 46)."-{$counter}";
            $counter++;
        }

        return $candidate;
    }

    private function authorize(User $actor, string $permission): void
    {
        abort_unless($actor->is_super_admin || $actor->can($permission), 403);
    }
}
