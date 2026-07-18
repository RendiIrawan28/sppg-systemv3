<?php

use App\Enums\MenuComponentType;

test('nutritionist workspace uses wet menu and dynamic cycle defaults', function (): void {
    expect(config('nutrition_menu.meal_type'))->toBe('lunch')
        ->and(config('nutrition_menu.default_cycle_length_days'))->toBe(5)
        ->and(config('nutrition_menu.minimum_cycle_length_days'))->toBe(1)
        ->and(config('nutrition_menu.maximum_cycle_length_days'))->toBe(60)
        ->and(config('nutrition_menu.default_buffer_percent'))->toBe(2);
});

test('mandatory and conditional components follow nutritionist decision', function (): void {
    expect(config('nutrition_menu.required_components'))->toBe([
        MenuComponentType::Staple->value,
        MenuComponentType::AnimalProtein->value,
        MenuComponentType::Vegetable->value,
        MenuComponentType::Fruit->value,
    ])->and(config('nutrition_menu.at_least_one_component_groups'))->toContain([
        MenuComponentType::PlantProtein->value,
        MenuComponentType::Milk->value,
    ]);
});

test('vegetable accepts multiple components', function (): void {
    expect(config('nutrition_menu.multiple_components'))
        ->toContain(MenuComponentType::Vegetable->value);
});
