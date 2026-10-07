<?php

namespace Tests\Feature;

use App\Models\BotUser;
use App\Models\Program;
use App\Models\ProgramField;
use App\Models\Registration;
use App\Models\RegistrationAnswer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function registerSomeoneFor(Program $program, string $platform = 'telegram', string $status = Registration::STATUS_CONFIRMED): Registration
    {
        $botUser = BotUser::create([
            'platform' => $platform, 'external_id' => (string) random_int(1, 999999), 'chat_id' => 'c',
        ]);

        return Registration::create([
            'program_id' => $program->id,
            'bot_user_id' => $botUser->id,
            'status' => $status,
            'tracking_code' => Registration::generateUniqueTrackingCode(),
        ]);
    }

    public function test_lists_registrations_for_a_program(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();
        $this->registerSomeoneFor($program);
        $this->registerSomeoneFor($program);
        $other = Program::factory()->create();
        $this->registerSomeoneFor($other);

        $response = $this->getJson("/api/programs/{$program->id}/registrations")->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_filters_registrations_by_status_and_platform(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();
        $this->registerSomeoneFor($program, 'telegram', Registration::STATUS_CONFIRMED);
        $this->registerSomeoneFor($program, 'rubika', Registration::STATUS_CANCELED);

        $this->getJson("/api/programs/{$program->id}/registrations?status=canceled")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'canceled');

        $this->getJson("/api/programs/{$program->id}/registrations?platform=rubika")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.bot_user.platform', 'rubika');
    }

    public function test_shows_registration_detail_with_answers_including_orphaned_field(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();
        $field = ProgramField::factory()->create(['program_id' => $program->id, 'key' => 'name', 'label' => 'Name']);
        $registration = $this->registerSomeoneFor($program);
        RegistrationAnswer::create([
            'registration_id' => $registration->id, 'program_field_id' => $field->id,
            'field_key' => 'name', 'field_label' => 'Name', 'value' => 'Ali',
        ]);
        $field->delete();

        $response = $this->getJson("/api/programs/{$program->id}/registrations/{$registration->id}")->assertOk();
        $this->assertSame('Ali', $response->json('data.answers.0.value'));
        $this->assertNull($response->json('data.answers.0.program_field_id'));
    }

    public function test_admin_can_cancel_a_confirmed_registration_freeing_capacity(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake();

        $this->actingAdmin();
        $program = Program::factory()->create(['capacity' => 1, 'title' => 'دوره تستی']);
        $registration = $this->registerSomeoneFor($program);

        $this->assertFalse($program->fresh()->hasCapacity());

        $this->patchJson("/api/programs/{$program->id}/registrations/{$registration->id}/cancel", ['reason' => 'ظرفیت جابه‌جا شد'])
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled')
            ->assertJsonPath('data.canceled_reason', 'admin');

        $this->assertTrue($program->fresh()->hasCapacity());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', 'دوره تستی')
            && str_contains($request['text'] ?? '', 'ظرفیت جابه‌جا شد'));
    }

    public function test_cannot_cancel_an_already_canceled_registration(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();
        $registration = $this->registerSomeoneFor($program, 'telegram', Registration::STATUS_CANCELED);

        $this->patchJson("/api/programs/{$program->id}/registrations/{$registration->id}/cancel")
            ->assertStatus(422);
    }

    public function test_exports_registrations_as_csv_including_orphaned_field_column(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();
        $field = ProgramField::factory()->create(['program_id' => $program->id, 'key' => 'city', 'label' => 'City']);
        $registration = $this->registerSomeoneFor($program);
        RegistrationAnswer::create([
            'registration_id' => $registration->id, 'program_field_id' => $field->id,
            'field_key' => 'city', 'field_label' => 'City', 'value' => 'Tehran',
        ]);

        $response = $this->get("/api/programs/{$program->id}/registrations/export");
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString($registration->tracking_code, $content);
        $this->assertStringContainsString('Tehran', $content);
    }

    public function test_dashboard_returns_aggregate_stats(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED, 'capacity' => 2]);
        $this->registerSomeoneFor($program);

        $response = $this->getJson('/api/dashboard')->assertOk();
        $this->assertSame(1, $response->json('data.programs_total'));
        $this->assertSame(1, $response->json('data.confirmed_registrations_total'));
        $this->assertCount(1, $response->json('data.recent_registrations'));
    }
}
