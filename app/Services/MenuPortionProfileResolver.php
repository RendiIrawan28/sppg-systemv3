<?php

namespace App\Services;

use App\Enums\MenuAudience;
use App\Enums\MenuPortionProfile;
use App\Models\BeneficiaryCategory;
use BackedEnum;

class MenuPortionProfileResolver
{
    public function profileForCategory(BeneficiaryCategory $category): MenuPortionProfile
    {
        $code = strtolower($this->enumValue($category->code));
        $audience = strtolower($this->enumValue($category->menu_audience));
        $portionSize = strtolower($this->enumValue($category->portion_size));

        if (in_array($code, ['balita', 'toddler'], true) || $audience === 'toddler') {
            return MenuPortionProfile::Toddler;
        }

        if (
            in_array($code, ['ibu_hamil', 'ibu_menyusui', 'bumil', 'busui'], true)
            || in_array($audience, ['pregnant_mother', 'breastfeeding_mother', 'maternal'], true)
        ) {
            return MenuPortionProfile::Maternal;
        }

        return $portionSize === 'small'
            ? MenuPortionProfile::Small
            : MenuPortionProfile::Large;
    }

    public function audienceForCategory(BeneficiaryCategory $category): MenuAudience
    {
        $code = strtolower($this->enumValue($category->code));
        $audience = strtolower($this->enumValue($category->menu_audience));

        if (in_array($code, ['balita', 'toddler'], true) || $audience === 'toddler') {
            return MenuAudience::Toddler;
        }

        if (
            in_array($code, ['ibu_hamil', 'ibu_menyusui', 'bumil', 'busui'], true)
            || in_array($audience, ['pregnant_mother', 'breastfeeding_mother', 'maternal'], true)
        ) {
            return MenuAudience::Maternal;
        }

        return MenuAudience::Student;
    }

    private function enumValue(mixed $value, string $default = ''): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value === null) {
            return $default;
        }

        return (string) $value;
    }
}
