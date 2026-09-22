<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class StoreProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string'],
            'registration_starts_at' => ['nullable', 'date'],
            'registration_ends_at' => ['nullable', 'date', 'after:registration_starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'requires_approval' => ['sometimes', 'boolean'],
            'success_message' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->filled('registration_starts_at') && Carbon::parse($this->input('registration_starts_at'))->lt(Carbon::now())) {
                $validator->errors()->add('registration_starts_at', 'تاریخ شروع ثبت‌نام باید بعد از الان باشد.');
            }
        });
    }
}
