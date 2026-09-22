<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'program_id' => $this->program_id,
            'label' => $this->label,
            'key' => $this->key,
            'type' => $this->type,
            'help_text' => $this->help_text,
            'options' => $this->options,
            'is_required' => $this->is_required,
            'is_editable_after_submit' => $this->is_editable_after_submit,
            'order' => $this->order,
            'validation_rules' => $this->validation_rules,
        ];
    }
}
