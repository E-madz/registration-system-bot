<?php

namespace Tests\Feature;

use App\Models\BotUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotUserListTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_lists_all_bot_users_by_default(): void
    {
        $this->actingAdmin();
        BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1']);
        BotUser::create(['platform' => 'bale', 'external_id' => '2', 'chat_id' => '2']);

        $this->getJson('/api/bot-users')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_filters_by_platform(): void
    {
        $this->actingAdmin();
        BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1']);
        BotUser::create(['platform' => 'bale', 'external_id' => '2', 'chat_id' => '2']);

        $response = $this->getJson('/api/bot-users?platform=bale')->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame('bale', $response->json('data.0.platform'));
    }

    public function test_filters_by_banned_status(): void
    {
        $this->actingAdmin();
        BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1', 'is_banned' => true]);
        BotUser::create(['platform' => 'telegram', 'external_id' => '2', 'chat_id' => '2', 'is_banned' => false]);

        $response = $this->getJson('/api/bot-users?is_banned=1')->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertTrue($response->json('data.0.is_banned'));
    }

    public function test_filters_by_blocked_status(): void
    {
        $this->actingAdmin();
        BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1', 'is_blocked' => true]);
        BotUser::create(['platform' => 'telegram', 'external_id' => '2', 'chat_id' => '2', 'is_blocked' => false]);

        $response = $this->getJson('/api/bot-users?is_blocked=1')->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertTrue($response->json('data.0.is_blocked'));
    }

    public function test_searches_by_name_username_phone_and_external_id(): void
    {
        $this->actingAdmin();
        $target = BotUser::create([
            'platform' => 'telegram', 'external_id' => 'unique-ext-id', 'chat_id' => '1',
            'first_name' => 'Ahmad', 'last_name' => 'Nazari', 'username' => 'ahmadn', 'phone' => '0912000',
        ]);
        BotUser::create(['platform' => 'telegram', 'external_id' => '2', 'chat_id' => '2', 'first_name' => 'Sara']);

        $this->getJson('/api/bot-users?search=Ahmad')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id);
        $this->getJson('/api/bot-users?search=ahmadn')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/bot-users?search=0912000')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/bot-users?search=unique-ext-id')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_filters_can_be_combined(): void
    {
        $this->actingAdmin();
        BotUser::create(['platform' => 'telegram', 'external_id' => '1', 'chat_id' => '1', 'is_banned' => true]);
        BotUser::create(['platform' => 'bale', 'external_id' => '2', 'chat_id' => '2', 'is_banned' => true]);

        $response = $this->getJson('/api/bot-users?platform=telegram&is_banned=1')->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame('telegram', $response->json('data.0.platform'));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/bot-users')->assertUnauthorized();
    }
}
