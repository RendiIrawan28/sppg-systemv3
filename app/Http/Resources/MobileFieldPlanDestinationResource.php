<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileFieldPlanDestinationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'type' => $this->destination_type,
            'code' => $this->destination_code_snapshot,
            'name' => $this->destination_name_snapshot,
            'address' => $this->address_snapshot,
            'contact_name' => $this->contact_name_snapshot,
            'contact_phone' => $this->contact_phone_snapshot,
            'route_name' => $this->route_name,
            'sequence_order' => (int) $this->sequence_order,
            'registered_beneficiaries' => (int) $this->registered_beneficiaries,
            'confirmed_beneficiaries' => (int) $this->confirmed_beneficiaries,
            'small_portions' => (int) $this->small_portions,
            'large_portions' => (int) $this->large_portions,
            'total_portions' => (int) $this->total_portions,
            'planned_departure_time' => $this->planned_departure_time ? substr((string) $this->planned_departure_time, 0, 5) : null,
            'planned_arrival_time' => $this->planned_arrival_time ? substr((string) $this->planned_arrival_time, 0, 5) : null,
            'confirmation_status' => $this->confirmation_status,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'change_reason' => $this->change_reason,
            'special_notes' => $this->special_notes,
            'recipient_groups' => MobileFieldPlanRecipientGroupResource::collection($this->whenLoaded('recipientGroups')),
        ];
    }
}
