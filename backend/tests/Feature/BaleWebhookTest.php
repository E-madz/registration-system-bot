<?php

namespace Tests\Feature;

use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BaleWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bale.bot_token' => 'test-token',
            'services.bale.webhook_secret' => 'test-secret',
        ]);

        Http::fake();
    }

    public function test_rejects_requests_with_an_invalid_secret(): void
    {
        $this->postJson('/api/webhook/bale/wrong-secret', [])->assertStatus(404);
    }

    public function test_start_command_shows_main_menu_via_bale_api(): void
    {
        $this->postJson('/api/webhook/bale/test-secret', [
            'message' => [
                'chat' => ['id' => 111],
                'from' => ['id' => 222, 'first_name' => 'Emad'],
                'text' => '/start',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('bot_users', ['platform' => 'bale', 'external_id' => '222']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'tapi.bale.ai/bottest-token/sendMessage')
            && isset($request['reply_markup']['keyboard']));
    }

    public function test_program_callback_shows_program_details_via_bale_api(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);

        $this->postJson('/api/webhook/bale/test-secret', [
            'callback_query' => [
                'id' => 'cbq1',
                'from' => ['id' => 222, 'first_name' => 'Emad'],
                'message' => ['chat' => ['id' => 111]],
                'data' => "program:{$program->id}",
            ],
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'tapi.bale.ai/bottest-token/sendMessage')
            && str_contains($request['text'] ?? '', $program->title));
    }
}
