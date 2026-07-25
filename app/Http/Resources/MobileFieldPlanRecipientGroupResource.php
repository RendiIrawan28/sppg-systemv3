<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileFieldPlanRecipientGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'category_code' => $this->beneficiary_category_code_snapshot,
            'category_name' => $this->beneficiary_category_name_snapshot,
            'menu_audience' => $this->menu_audience,
            'portion_size' => $this->portion_size,
            'registered_beneficiaries' => (int) $this->registered_beneficiaries,
            'confirmed_beneficiaries' => (int) $this->confirmed_beneficiaries,
            'small_portions' => (int) $this->small_portions,
            'large_portions' => (int) $this->large_portions,
            'total_portions' => (int) $this->total_portions,
            'notes' => $this->notes,
        ];
    }
}
