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

    /**
     * Once a program has ever had a registration, its existing fields are
     * structurally frozen - only label/help_text (and reordering, handled
     * elsewhere) may still change. This protects RegistrationAnswer rows
     * that were already shaped by this field's key/type/options at the
     * time they were submitted: changing those retroactively would make
     * old answers unreadable or misleading against the field's current
     * definition. Options are the one exception where we only block
     * removing an existing one - adding new ones is harmless.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $program = $this->route('program');
            $field = $this->route('field');

            if (! $program->hasAnyRegistration()) {
                return;
            }

            $normalize = fn ($value) => $value === null ? [] : $value;

            foreach (['key', 'type', 'validation_rules'] as $attribute) {
                if (! $this->has($attribute)) {
                    continue;
                }

                if ($normalize($this->input($attribute)) != $normalize($field->{$attribute})) {
                    $validator->errors()->add($attribute, 'چون این برنامه قبلاً ثبت‌نام داشته، امکان تغییر این مقدار برای فیلدهای موجود وجود ندارد.');
                }
            }

            if ($this->has('is_required')
                && filter_var($this->input('is_required'), FILTER_VALIDATE_BOOLEAN) !== $field->is_required) {
                $validator->errors()->add('is_required', 'چون این برنامه قبلاً ثبت‌نام داشته، امکان تغییر اجباری/اختیاری بودن فیلدهای موجود وجود ندارد.');
            }

            if ($this->has('options')) {
                $removed = array_diff($field->options ?? [], $this->input('options', []));
                if (! empty($removed)) {
                    $validator->errors()->add('options', 'چون این برنامه قبلاً ثبت‌نام داشته، نمی‌توان گزینه‌های موجود را حذف کرد؛ فقط افزودن گزینه‌ی جدید مجاز است.');
                }
            }
        });
    }
}
