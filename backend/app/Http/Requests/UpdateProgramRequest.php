<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $program = $this->route('program');

        return [
            'title' => ['sometimes', 'required', 'string', 'max:40'],
            'description' => ['nullable', 'string'],
            'registration_starts_at' => ['nullable', 'date'],
            'registration_ends_at' => ['nullable', 'date', 'after_or_equal:registration_starts_at'],
            'capacity' => [
                'nullable', 'integer', 'min:1',
                function ($attribute, $value, $fail) use ($program) {
                    $confirmed = $program->confirmedRegistrationsCount();
                    if ($value !== null && $value < $confirmed) {
                        $fail("ظرفیت نمی‌تواند کمتر از تعداد ثبت‌نام‌های تاییدشده‌ی فعلی ({$confirmed}) باشد.");
                    }
                },
            ],
            'success_message' => ['nullable', 'string'],
        ];
    }

    /**
     * Date-editing policy (independent of whether the program has any
     * registration yet - these hold even for a brand-new draft):
     *  - registration_ends_at can move either direction, but never to
     *    before right now (no backdating the deadline into the past).
     *  - registration_starts_at can move earlier freely, as long as it
     *    doesn't land before right now. It can only move LATER while the
     *    current start hasn't been reached yet - once registration has
     *    actually opened, postponing the start would contradict
     *    registrations that already happened under it.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $program = $this->route('program');
            $now = Carbon::now();

            if ($this->filled('registration_ends_at')) {
                $newEnds = Carbon::parse($this->input('registration_ends_at'));

                if ($newEnds->lt($now)) {
                    $validator->errors()->add('registration_ends_at', 'تاریخ پایان ثبت‌نام نمی‌تواند قبل از الان باشد.');
                }
            }

            if ($this->filled('registration_starts_at')) {
                $newStarts = Carbon::parse($this->input('registration_starts_at'));
                $oldStarts = $program->registration_starts_at;

                if ($newStarts->lt($now)) {
                    $validator->errors()->add('registration_starts_at', 'تاریخ شروع ثبت‌نام نمی‌تواند قبل از الان باشد.');
                } elseif ($oldStarts && $now->gte($oldStarts) && $newStarts->gt($oldStarts)) {
                    $validator->errors()->add('registration_starts_at', 'چون بازه‌ی ثبت‌نام قبلاً شروع شده، امکان عقب انداختن تاریخ شروع وجود ندارد.');
                }
            }
        });
    }
}
