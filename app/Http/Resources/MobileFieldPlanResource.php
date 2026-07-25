<?php

namespace App\Http\Resources;

use App\Enums\FieldDistributionPlanStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileFieldPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FieldDistributionPlanStatus|null $status */
        $status = $this->status;

        return [
            'id' => $this->getKey(),
            'uuid' => $this->uuid,
            'plan_number' => $this->plan_number,
            'distribution_date' => $this->distribution_date?->toDateString(),
            'service_date' => $this->service_date?->toDateString(),
            'production_date' => $this->production_date?->toDateString(),
            'menu_name' => $this->menu_name_snapshot,
            'is_rapel' => (bool) $this->is_rapel,
            'status' => $status?->value,
            'status_label' => $status?->label() ?? '-',
            'planned_beneficiaries' => (int) $this->planned_beneficiaries,
            'confirmed_beneficiaries' => (int) $this->confirmed_beneficiaries,
            'small_portions' => (int) $this->planned_small_portions,
            'large_portions' => (int) $this->planned_large_portions,
            'total_portions' => (int) $this->planned_total_portions,
            'destination_count' => (int) $this->destination_count,
            'confirmation_deadline_at' => $this->confirmation_deadline_at?->toIso8601String(),
            'general_notes' => $this->general_notes,
            'is_editable' => $this->isEditable(),
            'can_update' => $request->user()?->can('update', $this->resource) ?? false,
            'can_activate' => $this->isEditable()
                && ($request->user()?->can('field_planning.submit') ?? false),
            'destinations' => MobileFieldPlanDestinationResource::collection($this->whenLoaded('destinations')),
        ];
    }
}
