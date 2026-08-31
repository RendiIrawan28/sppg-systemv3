<?php

namespace Tests\Unit;

use App\Models\Menu;
use App\Models\MenuCycle;
use App\Models\MenuCycleDay;
use App\Models\MenuCycleDayVariant;
use App\Services\MenuAudienceMenuResolver;
use App\Services\MenuCycleExportService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MenuCycleDayVariantTest extends TestCase
{
    public function test_three_b_falls_back_to_main_menu_without_variant(): void
    {
        [$day, $main] = $this->dayWithMainMenu();

        $this->assertSame(
            $main,
            app(MenuAudienceMenuResolver::class)->effectiveMenu(
                $day,
                MenuAudienceMenuResolver::POSYANDU_3B,
            ),
        );
    }

    public function test_three_b_uses_variant_without_changing_school_menu(): void
    {
        [$day, $main] = $this->dayWithMainMenu();
        $variantMenu = new Menu(['name' => 'Menu Khusus 3B']);
        $variantMenu->id = 202;
        $variantMenu->setRelation('items', new Collection);
        $variant = new MenuCycleDayVariant([
            'audience_type' => MenuCycleDayVariant::AUDIENCE_POSYANDU_3B,
        ]);
        $variant->setRelation('menu', $variantMenu);
        $day->setRelation('variants', new Collection([$variant]));

        $resolver = app(MenuAudienceMenuResolver::class);

        $this->assertSame($main, $resolver->effectiveMenu($day, MenuAudienceMenuResolver::SCHOOL));
        $this->assertSame($variantMenu, $resolver->effectiveMenu($day, MenuAudienceMenuResolver::POSYANDU_3B));
    }

    public function test_export_switches_to_adaptive_mode_when_one_day_has_variant(): void
    {
        [$day, $main] = $this->dayWithMainMenu();
        $variantMenu = new Menu(['name' => 'Menu Khusus 3B']);
        $variantMenu->id = 202;
        $variantMenu->setRelation('items', new Collection);
        $variant = new MenuCycleDayVariant([
            'audience_type' => MenuCycleDayVariant::AUDIENCE_POSYANDU_3B,
        ]);
        $variant->id = 301;
        $variant->setRelation('menu', $variantMenu);
        $day->setRelation('variants', new Collection([$variant]));

        $cycle = new MenuCycle(['name' => 'Siklus Uji']);
        $cycle->setRelation('days', new Collection([$day]));
        $data = app(MenuCycleExportService::class)->prepare($cycle);

        $this->assertTrue($data['hasDifferent3BMenu']);
        $this->assertSame($main, $data['schoolMenus']->get(101));
        $this->assertSame($variantMenu, $data['threeBMenus']->get(101));
    }

    public function test_export_keeps_legacy_mode_when_all_three_b_menus_follow_main(): void
    {
        [$day, $main] = $this->dayWithMainMenu();
        $cycle = new MenuCycle(['name' => 'Siklus Lama']);
        $cycle->setRelation('days', new Collection([$day]));

        $data = app(MenuCycleExportService::class)->prepare($cycle);

        $this->assertFalse($data['hasDifferent3BMenu']);
        $this->assertSame($main, $data['threeBMenus']->get(101));
    }

    public function test_audience_mapping_groups_toddler_and_maternal_as_three_b(): void
    {
        $resolver = app(MenuAudienceMenuResolver::class);

        $this->assertSame(MenuAudienceMenuResolver::POSYANDU_3B, $resolver->allocationAudience(['menu_audience' => 'toddler']));
        $this->assertSame(MenuAudienceMenuResolver::POSYANDU_3B, $resolver->allocationAudience(['menu_audience' => 'maternal']));
        $this->assertSame(MenuAudienceMenuResolver::SCHOOL, $resolver->allocationAudience(['menu_audience' => 'student']));
    }

    public function test_migration_enforces_one_variant_per_day_and_audience(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/2026_08_31_210000_create_menu_cycle_day_variants.php'
        ));

        $this->assertIsString($migration);
        $this->assertStringContainsString("['menu_cycle_day_id', 'audience_type']", $migration);
        $this->assertStringContainsString("'menu_day_variant_unique'", $migration);
    }

    /** @return array{MenuCycleDay, Menu} */
    private function dayWithMainMenu(): array
    {
        $main = new Menu(['name' => 'Menu Utama']);
        $main->id = 201;
        $main->setRelation('items', new Collection);

        $day = new MenuCycleDay(['day_number' => 1]);
        $day->id = 101;
        $day->setRelation('menu', $main);
        $day->setRelation('variants', new Collection);

        return [$day, $main];
    }
}
