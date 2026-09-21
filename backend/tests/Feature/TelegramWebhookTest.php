<?php

namespace Tests\Feature;

use App\Models\BotUser;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
        ]);
    }

    private function webhookUrl(): string
    {
        return '/api/webhook/telegram/test-secret';
    }

    public function test_rejects_requests_with_an_invalid_secret(): void
    {
        $this->postJson('/api/webhook/telegram/wrong-secret', [])->assertStatus(404);
    }

    public function test_start_command_lists_published_programs_and_creates_bot_user(): void
    {
        Http::fake();
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);

        $this->postJson($this->webhookUrl(), [
            'message' => [
                'chat' => ['id' => 111],
                'from' => ['id' => 222, 'first_name' => 'Emad', 'username' => 'emawdz'],
                'text' => '/start',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('bot_users', [
            'platform' => 'telegram',
            'external_id' => '222',
            'first_name' => 'Emad',
        ]);

        Http::assertSent(function ($request) use ($program) {
            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] === '111'
                && $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] === "program:{$program->id}";
        });
    }

    public function test_program_callback_shows_program_details(): void
    {
        Http::fake();
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED, 'description' => 'توضیحات تست']);

        $this->postJson($this->webhookUrl(), [
            'callback_query' => [
                'id' => 'cbq1',
                'from' => ['id' => 222, 'first_name' => 'Emad'],
                'message' => ['chat' => ['id' => 111]],
                'data' => "program:{$program->id}",
            ],
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery'));
        Http::assertSent(function ($request) use ($program) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'], $program->title)
                && $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] === "register:{$program->id}";
        });
    }

    public function test_unrecognized_text_gets_a_fallback_reply(): void
    {
        Http::fake();

        $this->postJson($this->webhookUrl(), [
            'message' => [
                'chat' => ['id' => 111],
                'from' => ['id' => 222],
                'text' => 'random text',
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'], '/start');
        });
    }
}
