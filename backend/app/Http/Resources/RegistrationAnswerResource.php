<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'field_key' => $this->field_key,
            'field_label' => $this->field_label,
            'value' => $this->value,
            // null once the field it was answered for has since been
            // deleted - the label/value snapshot above still holds.
            'program_field_id' => $this->program_field_id,
        ];
    }
}
