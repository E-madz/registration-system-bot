<?php

namespace Tests\Feature;

use App\Models\BotUser;
use App\Models\Program;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotUserBanTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_banning_a_user_cancels_their_confirmed_registrations_and_frees_capacity(): void
    {
        $this->actingAdmin();

        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED, 'capacity' => 5]);
        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '111', 'chat_id' => '111']);
        $registration = Registration::create([
            'program_id' => $program->id,
            'bot_user_id' => $botUser->id,
            'status' => Registration::STATUS_CONFIRMED,
            'tracking_code' => Registration::generateUniqueTrackingCode(),
        ]);

        $this->assertSame(1, $program->fresh()->confirmedRegistrationsCount());

        $this->patchJson("/api/bot-users/{$botUser->id}/ban", ['reason' => 'spam'])
            ->assertOk()
            ->assertJsonPath('data.is_banned', true)
            ->assertJsonPath('data.ban_reason', 'spam');

        $registration->refresh();
        $this->assertSame(Registration::STATUS_CANCELED, $registration->status);
        $this->assertSame(Registration::REASON_BANNED, $registration->canceled_reason);
        $this->assertSame(0, $program->fresh()->confirmedRegistrationsCount());
    }

    public function test_unbanning_a_user_clears_the_ban_fields(): void
    {
        $this->actingAdmin();
        $botUser = BotUser::create([
            'platform' => 'telegram', 'external_id' => '111', 'chat_id' => '111',
            'is_banned' => true, 'banned_at' => now(), 'ban_reason' => 'spam',
        ]);

        $this->patchJson("/api/bot-users/{$botUser->id}/unban")
            ->assertOk()
            ->assertJsonPath('data.is_banned', false)
            ->assertJsonPath('data.ban_reason', null);
    }

    public function test_a_banned_user_gets_a_refusal_message_and_nothing_else_is_processed(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret']);
        Http::fake();

        $botUser = BotUser::create([
            'platform' => 'telegram', 'external_id' => '222', 'chat_id' => '222', 'is_banned' => true,
        ]);

        $this->postJson('/api/webhook/telegram/test-secret', [
            'message' => ['chat' => ['id' => 222], 'from' => ['id' => 222], 'text' => '/start'],
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'محروم شده'));

        $this->assertDatabaseMissing('conversation_states', ['bot_user_id' => $botUser->id]);
    }
}
