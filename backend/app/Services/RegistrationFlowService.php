<?php

namespace App\Services;

use App\Contracts\MessengerAdapter;
use App\DataTransferObjects\IncomingUpdate;
use App\DataTransferObjects\KeyboardButton;
use App\Models\BotUser;
use App\Models\ConversationState;
use App\Models\Program;
use App\Models\ProgramField;
use App\Models\Registration;
use App\Models\RegistrationAnswer;

class RegistrationFlowService
{
    private const MULTISELECT_TMP_KEY = '_multiselect_tmp';

    public function begin(BotUser $botUser, Program $program, MessengerAdapter $adapter): void
    {
        $chatId = $botUser->chat_id;

        $existing = Registration::query()
            ->where('bot_user_id', $botUser->id)
            ->where('program_id', $program->id)
            ->where('status', Registration::STATUS_CONFIRMED)
            ->first();

        if ($existing) {
            $adapter->sendMessage($chatId, "شما قبلاً برای این مورد ثبت‌نام کرده‌اید.\n🎫 کد پیگیری: {$existing->tracking_code}");

            return;
        }

        if (! $program->isAcceptingRegistrations()) {
            $adapter->sendMessage($chatId, $this->unavailabilityReason($program));

            return;
        }

        $fields = $program->fields()->get();

        $state = ConversationState::updateOrCreate(
            ['bot_user_id' => $botUser->id],
            [
                'program_id' => $program->id,
                'current_field_id' => $fields->first()?->id,
                'stage' => $fields->isEmpty() ? ConversationState::STAGE_CONFIRMING : ConversationState::STAGE_COLLECTING,
                'answers' => [],
            ],
        );

        if ($fields->isEmpty()) {
            $this->sendSummary($state->fresh(), $adapter);

            return;
        }

        $this->askField($state, $fields->first(), $adapter);
    }

    public function handleTextAnswer(ConversationState $state, IncomingUpdate $update, MessengerAdapter $adapter): void
    {
        $chatId = $state->botUser->chat_id;

        if ($state->stage === ConversationState::STAGE_CONFIRMING) {
            $adapter->sendMessage($chatId, 'لطفاً یکی از دکمه‌های زیر را انتخاب کنید:');
            $this->sendSummary($state, $adapter);

            return;
        }

        $field = $state->currentField;

        if (! $field) {
            $adapter->sendMessage($chatId, 'مشکلی پیش آمد. لطفاً دوباره ثبت‌نام را شروع کنید.');
            $state->delete();

            return;
        }

        if (in_array($field->type, ProgramField::CHOICE_TYPES, true)) {
            $adapter->sendMessage($chatId, 'لطفاً از دکمه‌های زیر پیام استفاده کنید.');

            return;
        }

        if ($field->type === 'file') {
            if (! $update->hasFile()) {
                $adapter->sendMessage($chatId, 'لطفاً یک عکس یا فایل ارسال کنید.');

                return;
            }

            $this->storeAnswerAndAdvance($state, $field, ['file_id' => $update->fileId, 'type' => $update->fileType], $adapter);

            return;
        }

        $error = $this->validate($field, $update->text ?? '');

        if ($error) {
            $adapter->sendMessage($chatId, $error);
            $this->askField($state, $field, $adapter);

            return;
        }

        $this->storeAnswerAndAdvance($state, $field, trim($update->text ?? ''), $adapter);
    }

    public function handleCallback(ConversationState $state, string $action, ?string $argument, MessengerAdapter $adapter): void
    {
        match ($action) {
            'field_back' => $this->goBack($state, $adapter),
            'field_skip' => $this->skip($state, $adapter),
            'field_choice' => $this->chooseSingle($state, (int) $argument, $adapter),
            'field_toggle' => $this->toggleMultiselect($state, (int) $argument, $adapter),
            'field_multi_confirm' => $this->confirmMultiselect($state, $adapter),
            'confirm_registration' => $this->finalize($state, $adapter),
            'cancel_registration_flow' => $this->cancel($state, $adapter),
            default => null,
        };
    }

    public function cancel(ConversationState $state, MessengerAdapter $adapter): void
    {
        $chatId = $state->botUser->chat_id;
        $state->delete();
        $adapter->sendMessage($chatId, 'ثبت‌نام لغو شد.');
    }

    private function unavailabilityReason(Program $program): string
    {
        if ($program->status !== Program::STATUS_PUBLISHED) {
            return 'این مورد دیگر برای ثبت‌نام در دسترس نیست.';
        }

        $window = $program->registrationWindowState();

        if ($window === 'not_started') {
            $date = $program->registration_starts_at?->format('Y-m-d H:i');

            return "ثبت‌نام این برنامه هنوز آغاز نشده است. تاریخ شروع: {$date}";
        }

        if ($window === 'ended') {
            return 'متأسفانه مهلت ثبت‌نام این برنامه به پایان رسیده است.';
        }

        return 'متأسفانه ظرفیت این برنامه تکمیل شده است و امکان ثبت‌نام وجود ندارد.';
    }

    private function orderedFields(Program $program)
    {
        return $program->fields()->get();
    }

    private function askField(ConversationState $state, ProgramField $field, MessengerAdapter $adapter): void
    {
        $chatId = $state->botUser->chat_id;
        $fields = $this->orderedFields($state->program);
        $index = $fields->search(fn (ProgramField $f) => $f->id === $field->id);

        $requiredMarker = $field->is_required ? ' (اجباری)' : ' (اختیاری)';
        $text = "سوال ".($index + 1)." از {$fields->count()}\n{$field->label}{$requiredMarker}";

        if ($field->help_text) {
            $text .= "\n{$field->help_text}";
        } elseif ($hint = $this->defaultHint($field->type)) {
            $text .= "\n{$hint}";
        }

        $previousAnswer = $state->answers[$field->key] ?? null;
        if ($previousAnswer !== null && ! is_array($previousAnswer)) {
            $text .= "\n\nپاسخ فعلی شما: {$previousAnswer}\nبرای تغییر، پاسخ جدید ارسال کنید.";
        }

        $keyboard = [];

        if ($field->type === 'select') {
            foreach ($field->options ?? [] as $i => $option) {
                $keyboard[] = [new KeyboardButton($option, "field_choice-{$i}")];
            }
        } elseif ($field->type === 'multiselect') {
            $selected = $state->answers[self::MULTISELECT_TMP_KEY] ?? [];
            foreach ($field->options ?? [] as $i => $option) {
                $prefix = in_array($i, $selected, true) ? '✅ ' : '⬜ ';
                $keyboard[] = [new KeyboardButton($prefix.$option, "field_toggle-{$i}")];
            }
            $keyboard[] = [new KeyboardButton('تایید انتخاب‌ها', 'field_multi_confirm')];
        }

        $controlRow = [];
        if (! $field->is_required) {
            $controlRow[] = new KeyboardButton('رد شدن', 'field_skip');
        }
        if ($index > 0) {
            $controlRow[] = new KeyboardButton('◀️ فیلد قبلی', 'field_back');
        }
        if ($controlRow) {
            $keyboard[] = $controlRow;
        }

        $adapter->sendMessage($chatId, $text, $keyboard ?: null);
    }

    private function validate(ProgramField $field, string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return $field->is_required ? 'این فیلد اجباری است. لطفاً پاسخ خود را ارسال کنید.' : null;
        }

        $rules = $field->validation_rules ?? [];

        return match ($field->type) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'فرمت ایمیل صحیح نیست. مثال: name@example.com',
            'number' => is_numeric($value) ? null : 'لطفاً فقط عدد وارد کنید.',
            'phone' => preg_match('/^[0-9+\-() ]{7,15}$/', $value) ? null : 'فرمت شماره تماس صحیح نیست. مثال: 09123456789',
            'date' => $this->isValidDateShape($value) ? null : 'فرمت تاریخ صحیح نیست. مثال: 1404/05/12',
            'text', 'textarea' => $this->validateLength($value, $rules),
            default => null,
        };
    }

    /**
     * Checks only that the value looks like a date (YYYY/MM/DD or YYYY-MM-DD
     * with a plausible month/day range) — not tied to a specific calendar,
     * since we don't validate real Jalali/Gregorian calendar math yet.
     */
    private function isValidDateShape(string $value): bool
    {
        return (bool) preg_match('/^\d{4}[\/-](0?[1-9]|1[0-2])[\/-](0?[1-9]|[12][0-9]|3[01])$/', $value);
    }

    private function defaultHint(string $type): ?string
    {
        return match ($type) {
            'email' => 'مثال: name@example.com',
            'phone' => 'مثال: 09123456789',
            'number' => 'لطفاً فقط عدد وارد کنید.',
            'date' => 'تاریخ را به‌صورت متن و با فرمت روز/ماه/سال وارد کنید. مثال: 1404/05/12',
            'file' => 'لطفاً یک عکس یا فایل ارسال کنید.',
            default => null,
        };
    }

    private function validateLength(string $value, array $rules): ?string
    {
        if (isset($rules['min']) && mb_strlen($value) < $rules['min']) {
            return "حداقل طول مجاز {$rules['min']} کاراکتر است.";
        }
        if (isset($rules['max']) && mb_strlen($value) > $rules['max']) {
            return "حداکثر طول مجاز {$rules['max']} کاراکتر است.";
        }

        return null;
    }

    private function storeAnswerAndAdvance(ConversationState $state, ProgramField $field, mixed $value, MessengerAdapter $adapter): void
    {
        $answers = $state->answers ?? [];
        $answers[$field->key] = $value;
        unset($answers[self::MULTISELECT_TMP_KEY]);

        $fields = $this->orderedFields($state->program);
        $index = $fields->search(fn (ProgramField $f) => $f->id === $field->id);
        $next = $fields->get($index + 1);

        if ($next) {
            $state->update([
                'answers' => $answers,
                'current_field_id' => $next->id,
            ]);
            $this->askField($state->fresh(), $next, $adapter);

            return;
        }

        $state->update([
            'answers' => $answers,
            'current_field_id' => null,
            'stage' => ConversationState::STAGE_CONFIRMING,
        ]);

        $this->sendSummary($state->fresh(), $adapter);
    }

    private function goBack(ConversationState $state, MessengerAdapter $adapter): void
    {
        $fields = $this->orderedFields($state->program);

        if ($state->stage === ConversationState::STAGE_CONFIRMING) {
            $last = $fields->last();
            $state->update(['stage' => ConversationState::STAGE_COLLECTING, 'current_field_id' => $last->id]);
            $this->askField($state->fresh(), $last, $adapter);

            return;
        }

        $index = $fields->search(fn (ProgramField $f) => $f->id === $state->current_field_id);
        if ($index === false || $index === 0) {
            return;
        }

        $previous = $fields->get($index - 1);
        $answers = $state->answers ?? [];

        if ($previous->type === 'multiselect') {
            $stored = $answers[$previous->key] ?? [];
            $options = $previous->options ?? [];
            $answers[self::MULTISELECT_TMP_KEY] = collect($options)
                ->keys()
                ->filter(fn ($i) => in_array($options[$i], (array) $stored, true))
                ->values()
                ->all();
        }

        $state->update(['current_field_id' => $previous->id, 'answers' => $answers]);
        $this->askField($state->fresh(), $previous, $adapter);
    }

    private function skip(ConversationState $state, MessengerAdapter $adapter): void
    {
        $field = $state->currentField;

        if (! $field || $field->is_required) {
            return;
        }

        $this->storeAnswerAndAdvance($state, $field, null, $adapter);
    }

    private function chooseSingle(ConversationState $state, int $index, MessengerAdapter $adapter): void
    {
        $field = $state->currentField;

        if (! $field || $field->type !== 'select' || ! isset($field->options[$index])) {
            return;
        }

        $this->storeAnswerAndAdvance($state, $field, $field->options[$index], $adapter);
    }

    private function toggleMultiselect(ConversationState $state, int $index, MessengerAdapter $adapter): void
    {
        $field = $state->currentField;

        if (! $field || $field->type !== 'multiselect' || ! isset($field->options[$index])) {
            return;
        }

        $answers = $state->answers ?? [];
        $selected = $answers[self::MULTISELECT_TMP_KEY] ?? [];

        if (in_array($index, $selected, true)) {
            $selected = array_values(array_diff($selected, [$index]));
        } else {
            $selected[] = $index;
        }

        $answers[self::MULTISELECT_TMP_KEY] = $selected;
        $state->update(['answers' => $answers]);

        $this->askField($state->fresh(), $field, $adapter);
    }

    private function confirmMultiselect(ConversationState $state, MessengerAdapter $adapter): void
    {
        $field = $state->currentField;

        if (! $field || $field->type !== 'multiselect') {
            return;
        }

        $answers = $state->answers ?? [];
        $selected = $answers[self::MULTISELECT_TMP_KEY] ?? [];

        if (empty($selected) && $field->is_required) {
            $adapter->sendMessage($state->botUser->chat_id, 'حداقل یک گزینه را انتخاب کنید.');

            return;
        }

        $options = $field->options ?? [];
        $values = array_values(array_intersect_key($options, array_flip($selected)));

        $this->storeAnswerAndAdvance($state, $field, $values, $adapter);
    }

    private function sendSummary(ConversationState $state, MessengerAdapter $adapter): void
    {
        $fields = $this->orderedFields($state->program);
        $lines = ['لطفاً اطلاعات زیر را بررسی کنید:'];

        foreach ($fields as $field) {
            $answer = $state->answers[$field->key] ?? null;
            $lines[] = "{$field->label}: ".$this->formatAnswerForDisplay($answer);
        }

        $keyboard = [[
            new KeyboardButton('✅ تایید و ثبت نهایی', 'confirm_registration'),
            new KeyboardButton('❌ انصراف', 'cancel_registration_flow'),
        ]];

        if ($fields->isNotEmpty()) {
            $keyboard[] = [new KeyboardButton('◀️ ویرایش آخرین فیلد', 'field_back')];
        }

        $adapter->sendMessage($state->botUser->chat_id, implode("\n", $lines), $keyboard);
    }

    private function formatAnswerForDisplay(mixed $answer): string
    {
        if ($answer === null) {
            return 'رد شد';
        }
        if (is_array($answer) && isset($answer['file_id'])) {
            return 'فایل ارسال شد';
        }
        if (is_array($answer)) {
            return implode('، ', $answer);
        }

        return (string) $answer;
    }

    private function finalize(ConversationState $state, MessengerAdapter $adapter): void
    {
        $chatId = $state->botUser->chat_id;
        $program = $state->program;

        if ($state->stage !== ConversationState::STAGE_CONFIRMING) {
            return;
        }

        if (! $program->isAcceptingRegistrations()) {
            $state->delete();
            $adapter->sendMessage($chatId, $this->unavailabilityReason($program));

            return;
        }

        $registration = Registration::create([
            'program_id' => $program->id,
            'bot_user_id' => $state->bot_user_id,
            'status' => Registration::STATUS_CONFIRMED,
            'tracking_code' => Registration::generateUniqueTrackingCode(),
        ]);

        foreach ($this->orderedFields($program) as $field) {
            RegistrationAnswer::create([
                'registration_id' => $registration->id,
                'program_field_id' => $field->id,
                'field_key' => $field->key,
                'field_label' => $field->label,
                'value' => $state->answers[$field->key] ?? null,
            ]);
        }

        $state->delete();

        $message = $program->success_message ?: 'ثبت‌نام شما با موفقیت ثبت شد.';
        $adapter->sendMessage($chatId, "{$message}\n\nکد پیگیری شما: {$registration->tracking_code}");
    }
}
