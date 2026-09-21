<?php

namespace App\Http\Requests;

use App\Models\ProgramField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProgramFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $programId = $this->route('program')->id;
        $fieldId = $this->route('field')->id;
        $type = $this->input('type', $this->route('field')->type);

        return [
            'label' => ['sometimes', 'required', 'string', 'max:40'],
            'key' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('program_fields', 'key')
                    ->where('program_id', $programId)
                    ->ignore($fieldId),
            ],
            'type' => ['sometimes', 'required', 'string', Rule::in(ProgramField::TYPES)],
            'help_text' => ['nullable', 'string', 'max:50'],
            'options' => [
                Rule::requiredIf(in_array($type, ProgramField::CHOICE_TYPES, true)),
                'nullable',
                'array',
            ],
            'options.*' => ['string'],
            'is_required' => ['sometimes', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
            'validation_rules' => ['nullable', 'array'],
            'validation_rules.min' => ['nullable', 'integer', 'min:0'],
            'validation_rules.max' => ['nullable', 'integer', 'min:0', 'gte:validation_rules.min'],
        ];
    }
}
