<?php

namespace Tests\Feature;

use App\Models\BotUser;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Program;
use App\Models\Registration;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function fakeTelegram(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake();
    }

    public function test_all_users_audience_reaches_every_bot_user_regardless_of_platform(): void
    {
        $this->fakeTelegram();
        config(['services.bale.bot_token' => 'test-token']);
        BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1']);
        BotUser::create(['platform' => 'bale', 'external_id' => '2', 'chat_id' => '2']);

        $notification = app(NotificationService::class)->create([
            'message' => 'hello',
            'source' => Notification::SOURCE_MANUAL,
            'audience_type' => Notification::AUDIENCE_ALL_USERS,
        ]);

        $this->assertSame(2, $notification->total_recipients);
        $this->assertSame(2, $notification->deliveries()->count());
    }

    public function test_program_registrants_audience_only_includes_confirmed_registrants_of_that_program(): void
    {
        $this->fakeTelegram();
        $program = Program::factory()->create();
        $otherProgram = Program::factory()->create();

        $confirmed = BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1']);
        $canceled = BotUser::create(['platform' => 'telegram', 'external_id' => '2', 'chat_id' => '2']);
        $unrelated = BotUser::create(['platform' => 'telegram', 'external_id' => '3', 'chat_id' => '3']);

        Registration::create(['program_id' => $program->id, 'bot_user_id' => $confirmed->id, 'status' => Registration::STATUS_CONFIRMED, 'tracking_code' => Registration::generateUniqueTrackingCode()]);
        Registration::create(['program_id' => $program->id, 'bot_user_id' => $canceled->id, 'status' => Registration::STATUS_CANCELED, 'tracking_code' => Registration::generateUniqueTrackingCode()]);
        Registration::create(['program_id' => $otherProgram->id, 'bot_user_id' => $unrelated->id, 'status' => Registration::STATUS_CONFIRMED, 'tracking_code' => Registration::generateUniqueTrackingCode()]);

        $notification = app(NotificationService::class)->create([
            'message' => 'hello',
            'source' => Notification::SOURCE_MANUAL,
            'audience_type' => Notification::AUDIENCE_PROGRAM_REGISTRANTS,
            'audience_program_id' => $program->id,
        ]);

        $this->assertSame(1, $notification->total_recipients);
        $this->assertSame($confirmed->id, $notification->deliveries()->first()->bot_user_id);
    }

    public function test_inactive_users_audience_includes_never_interacted_and_stale_users_only(): void
    {
        $this->fakeTelegram();
        $neverInteracted = BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1']);
        $stale = BotUser::create(['platform' => 'telegram', 'external_id' => '2', 'chat_id' => '2', 'last_interaction_at' => now()->subDays(40)]);
        $recent = BotUser::create(['platform' => 'telegram', 'external_id' => '3', 'chat_id' => '3', 'last_interaction_at' => now()->subDays(2)]);

        $notification = app(NotificationService::class)->create([
            'message' => 'hello',
            'source' => Notification::SOURCE_MANUAL,
            'audience_type' => Notification::AUDIENCE_INACTIVE_USERS,
            'inactive_days' => 30,
        ]);

        $recipientIds = $notification->deliveries()->pluck('bot_user_id')->sort()->values()->all();
        $this->assertSame([$neverInteracted->id, $stale->id], $recipientIds);
        $this->assertNotContains($recent->id, $recipientIds);
    }

    public function test_banned_and_blocked_users_are_skipped_and_counted_for_non_single_user_audiences(): void
    {
        $this->fakeTelegram();
        BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1']);
        BotUser::create(['platform' => 'telegram', 'external_id' => '2', 'chat_id' => '2', 'is_banned' => true]);
        BotUser::create(['platform' => 'telegram', 'external_id' => '3', 'chat_id' => '3', 'is_blocked' => true]);

        $notification = app(NotificationService::class)->create([
            'message' => 'hello',
            'source' => Notification::SOURCE_MANUAL,
            'audience_type' => Notification::AUDIENCE_ALL_USERS,
        ]);

        $this->assertSame(3, $notification->total_recipients);
        $this->assertSame(1, $notification->skipped_banned_count);
        $this->assertSame(1, $notification->skipped_blocked_count);
        $this->assertSame(1, $notification->fresh()->sent_count);

        $this->assertSame(1, $notification->deliveries()->where('status', NotificationDelivery::STATUS_SKIPPED_BANNED)->count());
        $this->assertSame(1, $notification->deliveries()->where('status', NotificationDelivery::STATUS_SKIPPED_BLOCKED)->count());
    }

    public function test_single_user_audience_bypasses_the_ban_filter_so_the_ban_notice_still_reaches_them(): void
    {
        $this->fakeTelegram();
        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1', 'is_banned' => true]);

        $notification = app(NotificationService::class)->create([
            'message' => 'you are banned',
            'source' => Notification::SOURCE_AUTO_BAN,
            'audience_type' => Notification::AUDIENCE_SINGLE_USER,
            'audience_bot_user_id' => $botUser->id,
        ]);

        $this->assertSame(1, $notification->total_recipients);
        $this->assertSame(0, $notification->skipped_banned_count);
        $this->assertSame(NotificationDelivery::STATUS_SENT, $notification->deliveries()->first()->status);
    }

    public function test_notification_is_marked_completed_once_every_delivery_reaches_a_terminal_state(): void
    {
        $this->fakeTelegram();
        BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1']);

        $notification = app(NotificationService::class)->create([
            'message' => 'hello',
            'source' => Notification::SOURCE_MANUAL,
            'audience_type' => Notification::AUDIENCE_ALL_USERS,
        ]);

        $this->assertSame(Notification::STATUS_COMPLETED, $notification->status);
        $this->assertNotNull($notification->completed_at);
    }

    public function test_a_platform_rejection_marks_the_delivery_failed_instead_of_sent(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400),
        ]);

        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '999999']);

        $notification = app(NotificationService::class)->create([
            'message' => 'hello',
            'source' => Notification::SOURCE_MANUAL,
            'audience_type' => Notification::AUDIENCE_ALL_USERS,
        ]);

        $delivery = $notification->deliveries()->first();
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertNotNull($delivery->error_message);
        $this->assertSame(1, $notification->fresh()->failed_count);
        $this->assertSame(0, $notification->fresh()->sent_count);
    }

    public function test_notification_with_no_eligible_recipients_completes_immediately_without_dispatching_jobs(): void
    {
        $this->fakeTelegram();
        BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1', 'is_banned' => true]);

        $notification = app(NotificationService::class)->create([
            'message' => 'hello',
            'source' => Notification::SOURCE_MANUAL,
            'audience_type' => Notification::AUDIENCE_ALL_USERS,
        ]);

        $this->assertSame(Notification::STATUS_COMPLETED, $notification->status);
        $this->assertSame(0, $notification->sent_count);
        $this->assertSame(1, $notification->skipped_banned_count);
    }

    public function test_program_published_notifies_all_users_with_a_button_linking_to_the_program(): void
    {
        $this->actingAdmin();
        $this->fakeTelegram();
        BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1']);

        $program = Program::factory()->create(['status' => Program::STATUS_DRAFT]);

        $this->patchJson("/api/programs/{$program->id}/status", ['status' => Program::STATUS_PUBLISHED])
            ->assertOk();

        $notification = Notification::where('source', Notification::SOURCE_AUTO_PROGRAM_PUBLISHED)->first();
        $this->assertNotNull($notification);
        $this->assertSame(Notification::AUDIENCE_ALL_USERS, $notification->audience_type);
        $this->assertSame($program->id, $notification->button_program_id);
        $this->assertNotNull($notification->button_label);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', $program->title));
    }

    public function test_program_canceled_notifies_only_its_confirmed_registrants_with_the_given_reason(): void
    {
        $this->actingAdmin();
        $this->fakeTelegram();

        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $registrant = BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1']);
        Registration::create(['program_id' => $program->id, 'bot_user_id' => $registrant->id, 'status' => Registration::STATUS_CONFIRMED, 'tracking_code' => Registration::generateUniqueTrackingCode()]);

        $this->patchJson("/api/programs/{$program->id}/status", ['status' => Program::STATUS_CANCELED, 'reason' => 'کمبود بودجه'])
            ->assertOk();

        $notification = Notification::where('source', Notification::SOURCE_AUTO_PROGRAM_CANCELED)->first();
        $this->assertNotNull($notification);
        $this->assertSame(Notification::AUDIENCE_PROGRAM_REGISTRANTS, $notification->audience_type);
        $this->assertSame(1, $notification->total_recipients);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'کمبود بودجه'));
    }

    public function test_manual_notification_can_be_created_via_the_api_and_lists_and_shows_correctly(): void
    {
        $this->actingAdmin();
        $this->fakeTelegram();
        BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1']);
        $program = Program::factory()->create();

        $response = $this->postJson('/api/notifications', [
            'message' => 'یک پیام تستی',
            'button_label' => 'مشاهده',
            'button_program_id' => $program->id,
            'audience_type' => Notification::AUDIENCE_ALL_USERS,
        ])->assertCreated();

        $notificationId = $response->json('data.id');
        $this->assertSame(1, $response->json('data.total_recipients'));

        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson("/api/notifications/{$notificationId}")
            ->assertOk()
            ->assertJsonPath('data.deliveries.0.status', NotificationDelivery::STATUS_SENT);
    }

    public function test_manual_notification_requires_audience_program_id_for_program_registrants_audience(): void
    {
        $this->actingAdmin();

        $this->postJson('/api/notifications', [
            'message' => 'hello',
            'audience_type' => Notification::AUDIENCE_PROGRAM_REGISTRANTS,
        ])->assertStatus(422)->assertJsonValidationErrors('audience_program_id');
    }

    public function test_manual_notification_cannot_target_the_single_user_audience(): void
    {
        $this->actingAdmin();

        $this->postJson('/api/notifications', [
            'message' => 'hello',
            'audience_type' => Notification::AUDIENCE_SINGLE_USER,
        ])->assertStatus(422)->assertJsonValidationErrors('audience_type');
    }

    public function test_the_unique_constraint_prevents_duplicate_deliveries_for_the_same_recipient(): void
    {
        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1']);
        $notification = Notification::create([
            'message' => 'hello',
            'source' => Notification::SOURCE_MANUAL,
            'audience_type' => Notification::AUDIENCE_ALL_USERS,
        ]);

        NotificationDelivery::create([
            'notification_id' => $notification->id,
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'status' => NotificationDelivery::STATUS_PENDING,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        NotificationDelivery::create([
            'notification_id' => $notification->id,
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'status' => NotificationDelivery::STATUS_PENDING,
        ]);
    }
}
