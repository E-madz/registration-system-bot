<?php

namespace App\Http\Requests;

use App\Models\Notification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:4096'],
            'button_label' => ['nullable', 'string', 'max:40', 'required_with:button_program_id'],
            'button_program_id' => ['nullable', 'integer', 'exists:programs,id', 'required_with:button_label'],
            'audience_type' => ['required', 'string', Rule::in([
                Notification::AUDIENCE_PROGRAM_REGISTRANTS,
                Notification::AUDIENCE_ALL_USERS,
                Notification::AUDIENCE_INACTIVE_USERS,
            ])],
            'audience_program_id' => ['nullable', 'integer', 'exists:programs,id', 'required_if:audience_type,'.Notification::AUDIENCE_PROGRAM_REGISTRANTS],
            'inactive_days' => ['nullable', 'integer', 'min:1', 'required_if:audience_type,'.Notification::AUDIENCE_INACTIVE_USERS],
            'audience_platform' => ['nullable', 'string', Rule::in(['telegram', 'bale', 'rubika'])],
        ];
    }
}
