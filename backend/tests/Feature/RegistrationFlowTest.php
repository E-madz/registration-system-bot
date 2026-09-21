<?php

namespace Tests\Feature;

use App\Models\BotUser;
use App\Models\Program;
use App\Models\ProgramField;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationFlowTest extends TestCase
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

    private function sendPhoto(): void
    {
        $this->postJson($this->webhookUrl(), [
            'message' => [
                'chat' => ['id' => 111],
                'from' => ['id' => 222, 'first_name' => 'Emad'],
                'photo' => [
                    ['file_id' => 'small_file_id', 'width' => 90, 'height' => 90],
                    ['file_id' => 'large_file_id', 'width' => 800, 'height' => 800],
                ],
            ],
        ])->assertOk();
    }

    public function test_happy_path_registers_with_a_required_text_field_and_optional_field(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'full_name', 'label' => 'Full name',
            'type' => 'text', 'is_required' => true, 'order' => 0,
        ]);
        ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'note', 'label' => 'Note',
            'type' => 'textarea', 'is_required' => false, 'order' => 1,
        ]);

        $this->sendCallback("register:{$program->id}");
        $this->sendMessage('Ali Mohammadi');
        $this->sendCallback('field_skip');
        $this->sendCallback('confirm_registration');

        $this->assertDatabaseHas('registrations', [
            'program_id' => $program->id,
            'status' => Registration::STATUS_CONFIRMED,
        ]);

        $registration = Registration::first();
        $this->assertNotNull($registration->tracking_code);
        $this->assertDatabaseHas('registration_answers', [
            'registration_id' => $registration->id,
            'field_key' => 'full_name',
        ]);
        $this->assertDatabaseCount('conversation_states', 0);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', $registration->tracking_code));
    }

    public function test_typing_text_during_confirmation_resends_summary_with_buttons(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'full_name', 'label' => 'Full name',
            'type' => 'text', 'is_required' => true, 'order' => 0,
        ]);

        $this->sendCallback("register:{$program->id}");
        $this->sendMessage('Ali Mohammadi');
        $this->sendMessage('این یه متن تصادفیه، نه دکمه');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'] ?? '', 'Full name: Ali Mohammadi')
                && $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 'confirm_registration';
        });

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_invalid_answer_is_rejected_and_field_is_asked_again(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'email', 'label' => 'Email',
            'type' => 'email', 'is_required' => true, 'order' => 0,
        ]);

        $this->sendCallback("register:{$program->id}");
        $this->sendMessage('not-an-email');

        $state = \App\Models\ConversationState::first();
        $this->assertNotNull($state);
        $this->assertEquals(ProgramField::first()->id, $state->current_field_id);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'ایمیل'));
    }

    public function test_date_field_shows_format_hint_and_rejects_bad_input(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'birth_date', 'label' => 'Birth date',
            'type' => 'date', 'is_required' => true, 'order' => 0,
        ]);

        $this->sendCallback("register:{$program->id}");

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', '1404/05/12'));

        $this->sendMessage('not a date');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'فرمت تاریخ صحیح نیست'));

        $this->sendMessage('1404/05/12');

        $this->sendCallback('confirm_registration');

        $registration = Registration::first();
        $this->assertDatabaseHas('registration_answers', [
            'registration_id' => $registration->id,
            'field_key' => 'birth_date',
        ]);
    }

    public function test_file_field_shows_hint_and_accepts_a_photo_upload(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'id_card', 'label' => 'ID card photo',
            'type' => 'file', 'is_required' => true, 'order' => 0,
        ]);

        $this->sendCallback("register:{$program->id}");

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'عکس یا فایل ارسال کنید'));

        // A plain text answer must be rejected for a file field.
        $this->sendMessage('this is not a file');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'لطفاً یک عکس یا فایل ارسال کنید.'));

        $this->sendPhoto();
        $this->sendCallback('confirm_registration');

        $registration = Registration::first();
        $this->assertNotNull($registration);
        $answer = $registration->answers()->where('field_key', 'id_card')->first();
        $this->assertEquals('large_file_id', $answer->value['file_id']);
        $this->assertEquals('photo', $answer->value['type']);
    }

    public function test_back_button_returns_to_previous_field(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $first = ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'name', 'label' => 'Name',
            'type' => 'text', 'is_required' => true, 'order' => 0,
        ]);
        $second = ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'age', 'label' => 'Age',
            'type' => 'number', 'is_required' => true, 'order' => 1,
        ]);

        $this->sendCallback("register:{$program->id}");
        $this->sendMessage('Ali');
        $this->sendCallback('field_back');

        $state = \App\Models\ConversationState::first();
        $this->assertEquals($first->id, $state->current_field_id);
        $this->assertEquals('Ali', $state->answers['name']);
    }

    public function test_select_field_via_button(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'level', 'label' => 'Level',
            'type' => 'select', 'options' => ['Beginner', 'Advanced'], 'is_required' => true, 'order' => 0,
        ]);

        $this->sendCallback("register:{$program->id}");
        $this->sendCallback('field_choice:1');
        $this->sendCallback('confirm_registration');

        $registration = Registration::first();
        $this->assertDatabaseHas('registration_answers', [
            'registration_id' => $registration->id,
            'field_key' => 'level',
        ]);
        $answer = $registration->answers()->where('field_key', 'level')->first();
        $this->assertEquals('Advanced', $answer->value);
    }

    public function test_multiselect_field_via_toggle_and_confirm(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'interests', 'label' => 'Interests',
            'type' => 'multiselect', 'options' => ['Music', 'Sport', 'Art'], 'is_required' => true, 'order' => 0,
        ]);

        $this->sendCallback("register:{$program->id}");
        $this->sendCallback('field_toggle:0');
        $this->sendCallback('field_toggle:2');
        $this->sendCallback('field_multi_confirm');
        $this->sendCallback('confirm_registration');

        $registration = Registration::first();
        $answer = $registration->answers()->where('field_key', 'interests')->first();
        $this->assertEquals(['Music', 'Art'], array_values($answer->value));
    }

    public function test_cancel_registration_flow_deletes_conversation_state(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create(['program_id' => $program->id, 'order' => 0]);

        $this->sendCallback("register:{$program->id}");
        $this->sendCallback('cancel_registration_flow');

        $this->assertDatabaseCount('conversation_states', 0);
        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_cannot_register_twice_for_the_same_program(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create(['program_id' => $program->id, 'order' => 0, 'is_required' => false]);

        $this->sendCallback("register:{$program->id}");
        $this->sendCallback('field_skip');
        $this->sendCallback('confirm_registration');
        $this->assertDatabaseCount('registrations', 1);

        $this->sendCallback("register:{$program->id}");
        $this->assertDatabaseCount('conversation_states', 0);
    }

    public function test_registration_blocked_when_capacity_is_full(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED, 'capacity' => 1]);
        $otherUser = BotUser::create(['platform' => 'telegram', 'external_id' => '999']);
        Registration::create([
            'program_id' => $program->id,
            'bot_user_id' => $otherUser->id,
            'status' => Registration::STATUS_CONFIRMED,
            'tracking_code' => Registration::generateUniqueTrackingCode(),
        ]);

        $this->sendCallback("register:{$program->id}");

        $this->assertDatabaseCount('conversation_states', 0);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'وجود ندارد'));
    }

    public function test_user_can_cancel_a_confirmed_registration(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '222']);
        $registration = Registration::create([
            'program_id' => $program->id,
            'bot_user_id' => $botUser->id,
            'status' => Registration::STATUS_CONFIRMED,
            'tracking_code' => Registration::generateUniqueTrackingCode(),
        ]);

        $this->sendCallback("cancel_registration:{$registration->id}");

        $this->assertDatabaseHas('registrations', [
            'id' => $registration->id,
            'status' => Registration::STATUS_CANCELED,
        ]);
    }

    public function test_menu_command_implicitly_cancels_active_registration_flow(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create(['program_id' => $program->id, 'order' => 0]);

        $this->sendCallback("register:{$program->id}");
        $this->assertDatabaseCount('conversation_states', 1);

        $this->sendMessage('/start');

        $this->assertDatabaseCount('conversation_states', 0);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'لغو شد'));
    }
}
