<?php

namespace Tests\Feature;

use App\Models\BotUser;
use App\Models\Program;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotBlockedDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_403_blocked_response_marks_the_user_blocked_and_cancels_their_registration(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret']);

        Http::fake([
            'api.telegram.org/*' => Http::response(
                ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'],
                403,
            ),
        ]);

        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '333', 'chat_id' => '333']);
        $registration = Registration::create([
            'program_id' => $program->id,
            'bot_user_id' => $botUser->id,
            'status' => Registration::STATUS_CONFIRMED,
            'tracking_code' => Registration::generateUniqueTrackingCode(),
        ]);

        $this->postJson('/api/webhook/telegram/test-secret', [
            'message' => ['chat' => ['id' => 333], 'from' => ['id' => 333], 'text' => '/start'],
        ])->assertOk();

        $botUser->refresh();
        $this->assertTrue($botUser->is_blocked);
        $this->assertNotNull($botUser->blocked_at);

        $registration->refresh();
        $this->assertSame(Registration::STATUS_CANCELED, $registration->status);
        $this->assertSame(Registration::REASON_BOT_BLOCKED, $registration->canceled_reason);
    }

    public function test_an_unrelated_telegram_failure_does_not_mark_the_user_blocked(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request'], 400),
        ]);

        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '444', 'chat_id' => '444']);

        $this->postJson('/api/webhook/telegram/test-secret', [
            'message' => ['chat' => ['id' => 444], 'from' => ['id' => 444], 'text' => '/start'],
        ])->assertOk();

        $this->assertFalse($botUser->refresh()->is_blocked);
    }
}
