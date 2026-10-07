<?php

namespace Tests\Feature;

use App\Models\BotUser;
use App\Models\Program;
use App\Models\ProgramField;
use App\Models\Registration;
use App\Models\RegistrationAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationAnswerEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
        ]);

        Http::fake();
    }

    private function webhookUrl(): string
    {
        return '/api/webhook/telegram/test-secret';
    }

    private function sendMessage(string $text): void
    {
        $this->postJson($this->webhookUrl(), [
            'message' => [
                'chat' => ['id' => 111],
                'from' => ['id' => 222, 'first_name' => 'Emad'],
                'text' => $text,
            ],
        ])->assertOk();
    }

    private function sendCallback(string $data): void
    {
        $this->postJson($this->webhookUrl(), [
            'callback_query' => [
                'id' => 'cbq-'.uniqid(),
                'from' => ['id' => 222, 'first_name' => 'Emad'],
                'message' => ['chat' => ['id' => 111]],
                'data' => $data,
            ],
        ])->assertOk();
    }

    private function makeConfirmedRegistration(Program $program, BotUser $botUser): Registration
    {
        return Registration::create([
            'program_id' => $program->id,
            'bot_user_id' => $botUser->id,
            'status' => Registration::STATUS_CONFIRMED,
            'tracking_code' => Registration::generateUniqueTrackingCode(),
        ]);
    }

    public function test_view_registration_shows_an_edit_button_only_for_editable_fields(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $editableField = ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'nickname', 'label' => 'نام مستعار',
            'type' => 'text', 'is_required' => false, 'is_editable_after_submit' => true, 'order' => 0,
        ]);
        $lockedField = ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'national_id', 'label' => 'کد ملی',
            'type' => 'text', 'is_required' => true, 'is_editable_after_submit' => false, 'order' => 1,
        ]);

        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '222', 'chat_id' => '111']);
        $registration = $this->makeConfirmedRegistration($program, $botUser);
        RegistrationAnswer::create(['registration_id' => $registration->id, 'program_field_id' => $editableField->id, 'field_key' => 'nickname', 'field_label' => 'نام مستعار', 'value' => 'علی']);
        RegistrationAnswer::create(['registration_id' => $registration->id, 'program_field_id' => $lockedField->id, 'field_key' => 'national_id', 'field_label' => 'کد ملی', 'value' => '0012345678']);

        $this->sendCallback("view_registration-{$registration->id}");

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && isset($request['reply_markup']['inline_keyboard'])
            && collect($request['reply_markup']['inline_keyboard'])->flatten(1)->contains(fn ($btn) => $btn['callback_data'] === "edit_answer-{$registration->id}_{$editableField->id}")
            && ! collect($request['reply_markup']['inline_keyboard'])->flatten(1)->contains(fn ($btn) => $btn['callback_data'] === "edit_answer-{$registration->id}_{$lockedField->id}"));
    }

    public function test_editing_a_text_field_updates_the_existing_answer_in_place(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $field = ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'nickname', 'label' => 'نام مستعار',
            'type' => 'text', 'is_required' => false, 'is_editable_after_submit' => true, 'order' => 0,
        ]);

        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '222', 'chat_id' => '111']);
        $registration = $this->makeConfirmedRegistration($program, $botUser);
        $answer = RegistrationAnswer::create(['registration_id' => $registration->id, 'program_field_id' => $field->id, 'field_key' => 'nickname', 'field_label' => 'نام مستعار', 'value' => 'علی']);

        $this->sendCallback("edit_answer-{$registration->id}_{$field->id}");
        $this->assertDatabaseHas('conversation_states', ['bot_user_id' => $botUser->id, 'editing_registration_id' => $registration->id]);

        $this->sendMessage('علیرضا');

        $answer->refresh();
        $this->assertSame('علیرضا', $answer->value);
        $this->assertDatabaseCount('registrations', 1);
        $this->assertDatabaseCount('conversation_states', 0);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'بروزرسانی شد'));
    }

    public function test_editing_a_select_field_updates_the_answer(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $field = ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'size', 'label' => 'سایز',
            'type' => 'select', 'options' => ['S', 'M', 'L'], 'is_required' => true,
            'is_editable_after_submit' => true, 'order' => 0,
        ]);

        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '222', 'chat_id' => '111']);
        $registration = $this->makeConfirmedRegistration($program, $botUser);
        $answer = RegistrationAnswer::create(['registration_id' => $registration->id, 'program_field_id' => $field->id, 'field_key' => 'size', 'field_label' => 'سایز', 'value' => 'S']);

        $this->sendCallback("edit_answer-{$registration->id}_{$field->id}");
        $this->sendCallback('field_choice-2');

        $this->assertSame('L', $answer->refresh()->value);
    }

    public function test_editing_a_file_field_replaces_the_stored_answer(): void
    {
        // Deliberately relies on setUp()'s bare Http::fake() (no download
        // actually needs to succeed for this assertion) rather than a
        // pattern-specific one - a bare Http::fake() registered in setUp()
        // is an unconditional "match everything" stub, and since stubs are
        // checked in registration order, a more specific Http::fake([...])
        // added here would be silently ignored anyway.
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $field = ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'photo', 'label' => 'عکس',
            'type' => 'file', 'is_required' => false, 'is_editable_after_submit' => true, 'order' => 0,
        ]);

        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '222', 'chat_id' => '111']);
        $registration = $this->makeConfirmedRegistration($program, $botUser);
        $answer = RegistrationAnswer::create([
            'registration_id' => $registration->id, 'program_field_id' => $field->id,
            'field_key' => 'photo', 'field_label' => 'عکس', 'value' => ['file_id' => 'old_file_id', 'type' => 'photo'],
        ]);

        $this->sendCallback("edit_answer-{$registration->id}_{$field->id}");

        $this->postJson($this->webhookUrl(), [
            'message' => [
                'chat' => ['id' => 111],
                'from' => ['id' => 222],
                'photo' => [['file_id' => 'new_file_id', 'width' => 800, 'height' => 800]],
            ],
        ])->assertOk();

        $answer->refresh();
        $this->assertSame('new_file_id', $answer->value['file_id']);
    }

    public function test_editing_is_rejected_once_the_program_is_closed(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_CLOSED]);
        $field = ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'nickname', 'label' => 'نام مستعار',
            'type' => 'text', 'is_editable_after_submit' => true, 'order' => 0,
        ]);

        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '222', 'chat_id' => '111']);
        $registration = $this->makeConfirmedRegistration($program, $botUser);
        $answer = RegistrationAnswer::create(['registration_id' => $registration->id, 'program_field_id' => $field->id, 'field_key' => 'nickname', 'field_label' => 'نام مستعار', 'value' => 'علی']);

        $this->sendCallback("edit_answer-{$registration->id}_{$field->id}");

        $this->assertDatabaseCount('conversation_states', 0);
        $this->assertSame('علی', $answer->refresh()->value);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'امکان ویرایش این اطلاعات در حال حاضر وجود ندارد'));
    }

    public function test_editing_is_rejected_for_a_canceled_registration(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $field = ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'nickname', 'label' => 'نام مستعار',
            'type' => 'text', 'is_editable_after_submit' => true, 'order' => 0,
        ]);

        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '222', 'chat_id' => '111']);
        $registration = $this->makeConfirmedRegistration($program, $botUser);
        $registration->update(['status' => Registration::STATUS_CANCELED, 'canceled_at' => now(), 'canceled_reason' => Registration::REASON_SELF]);
        $answer = RegistrationAnswer::create(['registration_id' => $registration->id, 'program_field_id' => $field->id, 'field_key' => 'nickname', 'field_label' => 'نام مستعار', 'value' => 'علی']);

        $this->sendCallback("edit_answer-{$registration->id}_{$field->id}");

        $this->assertDatabaseCount('conversation_states', 0);
        $this->assertSame('علی', $answer->refresh()->value);
    }

    public function test_editing_a_non_editable_field_is_rejected_even_with_a_crafted_callback(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $field = ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'national_id', 'label' => 'کد ملی',
            'type' => 'text', 'is_editable_after_submit' => false, 'order' => 0,
        ]);

        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '222', 'chat_id' => '111']);
        $registration = $this->makeConfirmedRegistration($program, $botUser);
        $answer = RegistrationAnswer::create(['registration_id' => $registration->id, 'program_field_id' => $field->id, 'field_key' => 'national_id', 'field_label' => 'کد ملی', 'value' => '0012345678']);

        $this->sendCallback("edit_answer-{$registration->id}_{$field->id}");

        $this->assertDatabaseCount('conversation_states', 0);
        $this->assertSame('0012345678', $answer->refresh()->value);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'این فیلد قابل ویرایش نیست'));
    }

    public function test_canceling_a_field_edit_leaves_the_original_answer_untouched(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $field = ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'nickname', 'label' => 'نام مستعار',
            'type' => 'text', 'is_editable_after_submit' => true, 'order' => 0,
        ]);

        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '222', 'chat_id' => '111']);
        $registration = $this->makeConfirmedRegistration($program, $botUser);
        $answer = RegistrationAnswer::create(['registration_id' => $registration->id, 'program_field_id' => $field->id, 'field_key' => 'nickname', 'field_label' => 'نام مستعار', 'value' => 'علی']);

        $this->sendCallback("edit_answer-{$registration->id}_{$field->id}");
        $this->sendCallback('cancel_registration_flow');

        $this->assertDatabaseCount('conversation_states', 0);
        $this->assertSame('علی', $answer->refresh()->value);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'ویرایش لغو شد'));
    }
}
