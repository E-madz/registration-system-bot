<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\ProgramField;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RubikaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.rubika.bot_token' => 'test-token',
            'services.rubika.webhook_secret' => 'test-secret',
        ]);

        Http::fake();
    }

    private function sendMessage(string $text): void
    {
        $this->postJson('/api/webhook/rubika/test-secret', [
            'update' => [
                'type' => 'NewMessage',
                'chat_id' => 'c111',
                'new_message' => [
                    'message_id' => 'm1',
                    'sender_id' => 'u222',
                    'text' => $text,
                ],
            ],
        ])->assertOk();
    }

    private function sendButtonTap(string $buttonId): void
    {
        $this->postJson('/api/webhook/rubika/test-secret', [
            'update' => [
                'type' => 'NewMessage',
                'chat_id' => 'c111',
                'new_message' => [
                    'message_id' => 'm2',
                    'sender_id' => 'u222',
                    'aux_data' => ['button_id' => $buttonId],
                ],
            ],
        ])->assertOk();
    }

    private function sendInlineTap(string $buttonId): void
    {
        $this->postJson('/api/webhook/rubika/test-secret', [
            'inline_message' => [
                'chat_id' => 'c111',
                'sender_id' => 'u222',
                'message_id' => 'm3',
                'aux_data' => ['button_id' => $buttonId],
            ],
        ])->assertOk();
    }

    public function test_rejects_requests_with_an_invalid_secret(): void
    {
        $this->postJson('/api/webhook/rubika/wrong-secret', [])->assertStatus(404);
    }

    public function test_start_command_shows_main_menu_via_rubika_api(): void
    {
        $this->sendMessage('/start');

        $this->assertDatabaseHas('bot_users', ['platform' => 'rubika', 'external_id' => 'u222']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'botapi.rubika.ir/v3/test-token/sendMessage')
            && $request['chat_id'] === 'c111'
            && isset($request['chat_keypad']));
    }

    public function test_chat_keypad_button_tap_is_treated_as_a_callback(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);

        $this->sendButtonTap("program:{$program->id}");

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', $program->title));
    }

    public function test_inline_keypad_button_tap_is_treated_as_a_callback(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);

        $this->sendInlineTap("program:{$program->id}");

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', $program->title));
    }

    public function test_file_field_accepts_an_uploaded_file_via_rubika(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'id_card', 'label' => 'ID card',
            'type' => 'file', 'is_required' => true, 'order' => 0,
        ]);

        $this->sendInlineTap("register:{$program->id}");

        $this->postJson('/api/webhook/rubika/test-secret', [
            'update' => [
                'type' => 'NewMessage',
                'chat_id' => 'c111',
                'new_message' => [
                    'message_id' => 'm4',
                    'sender_id' => 'u222',
                    'file' => ['file_id' => 'rubika-file-1', 'file_name' => 'card.jpg', 'size' => 1024],
                ],
            ],
        ])->assertOk();

        $this->sendInlineTap('confirm_registration');

        $registration = Registration::first();
        $this->assertNotNull($registration);
        $answer = $registration->answers()->where('field_key', 'id_card')->first();
        $this->assertEquals('rubika-file-1', $answer->value['file_id']);
    }

    public function test_full_registration_flow_via_rubika(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'name', 'label' => 'Name',
            'type' => 'text', 'is_required' => true, 'order' => 0,
        ]);

        $this->sendInlineTap("register:{$program->id}");
        $this->sendMessage('Ali');
        $this->sendInlineTap('confirm_registration');

        $this->assertDatabaseHas('registrations', [
            'program_id' => $program->id,
            'status' => Registration::STATUS_CONFIRMED,
        ]);
    }
}
