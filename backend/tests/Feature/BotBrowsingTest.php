<?php

namespace Tests\Feature;

use App\Models\BotUser;
use App\Models\Program;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotBrowsingTest extends TestCase
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

    public function test_all_programs_menu_shows_empty_state_message(): void
    {
        $this->sendMessage('📋 مشاهده همه رویدادهای فعال');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'هیچ برنامهٔ فعالی'));
    }

    public function test_my_programs_shows_empty_state_message(): void
    {
        $this->sendMessage('🎫 برنامه‌های من');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'هنوز در هیچ برنامه‌ای ثبت‌نام نکرده'));
    }

    public function test_my_programs_lists_confirmed_registrations(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED, 'title' => 'Camp']);
        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '222']);
        Registration::create([
            'program_id' => $program->id,
            'bot_user_id' => $botUser->id,
            'status' => Registration::STATUS_CONFIRMED,
            'tracking_code' => Registration::generateUniqueTrackingCode(),
        ]);

        $this->sendMessage('🎫 برنامه‌های من');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] === "program-{$program->id}");
    }

    public function test_search_with_single_match_shows_detail_directly(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED, 'title' => 'Unique Camp Name']);

        $this->sendMessage('Unique Camp');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', $program->title)
            && $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] === "register-{$program->id}");
    }

    public function test_search_with_multiple_matches_shows_a_list(): void
    {
        Program::factory()->create(['status' => Program::STATUS_PUBLISHED, 'title' => 'Camp A']);
        Program::factory()->create(['status' => Program::STATUS_PUBLISHED, 'title' => 'Camp B']);

        $this->sendMessage('Camp');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'نتایج جستجو')
            && count($request['reply_markup']['inline_keyboard']) === 2);
    }

    public function test_closed_program_detail_without_confirmed_registration(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_CLOSED]);

        $this->sendCallback("program-{$program->id}");

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'مهلت ثبت‌نام این برنامه پایان یافته'));
    }

    public function test_canceled_program_detail(): void
    {
        $program = Program::factory()->create(['status' => Program::STATUS_CANCELED]);

        $this->sendCallback("program-{$program->id}");

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'لغو شده است'));
    }

    public function test_registration_not_yet_open_shows_start_date(): void
    {
        $program = Program::factory()->create([
            'status' => Program::STATUS_PUBLISHED,
            'registration_starts_at' => now()->addDays(3),
        ]);

        $this->sendCallback("program-{$program->id}");

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'هنوز آغاز نشده'));
    }
}
