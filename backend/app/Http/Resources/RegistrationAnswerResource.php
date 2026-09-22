<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class RegistrationAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $value = $this->value;
        $fileUrl = is_array($value) && isset($value['stored_path'])
            ? Storage::disk('public')->url($value['stored_path'])
            : null;

        return [
            'field_key' => $this->field_key,
            'field_label' => $this->field_label,
            'value' => $value,
            // Only present when we actually managed to download the file
            // the user sent (see MessengerAdapter::downloadFile) - a bare
            // file_id from the platform isn't downloadable by us directly.
            'file_url' => $fileUrl,
            // null once the field it was answered for has since been
            // deleted - the label/value snapshot above still holds.
            'program_field_id' => $this->program_field_id,
        ];
    }
}
