<?php

namespace Tests\Feature;

use App\Models\BotUser;
use App\Models\Program;
use App\Models\ProgramField;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramFieldLockTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function registerSomeoneFor(Program $program, string $status = Registration::STATUS_CONFIRMED): Registration
    {
        $botUser = BotUser::create(['platform' => 'telegram', 'external_id' => (string) random_int(1000, 9999)]);

        return Registration::create([
            'program_id' => $program->id,
            'bot_user_id' => $botUser->id,
            'status' => $status,
            'tracking_code' => Registration::generateUniqueTrackingCode(),
        ]);
    }

    public function test_new_required_field_is_rejected_once_the_program_has_a_registration(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $this->registerSomeoneFor($program);

        $this->postJson("/api/programs/{$program->id}/fields", [
            'label' => 'Shirt size', 'key' => 'shirt_size', 'type' => 'text', 'is_required' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('is_required');

        $this->postJson("/api/programs/{$program->id}/fields", [
            'label' => 'Shirt size', 'key' => 'shirt_size', 'type' => 'text', 'is_required' => false,
        ])->assertCreated();
    }

    public function test_new_field_is_unrestricted_when_the_program_has_no_registrations(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $this->postJson("/api/programs/{$program->id}/fields", [
            'label' => 'Shirt size', 'key' => 'shirt_size', 'type' => 'text', 'is_required' => true,
        ])->assertCreated();
    }

    public function test_key_type_and_required_cannot_change_once_the_program_has_a_registration(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $field = ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'age', 'type' => 'number', 'is_required' => false,
        ]);
        $this->registerSomeoneFor($program);

        $this->putJson("/api/programs/{$program->id}/fields/{$field->id}", ['key' => 'age_changed'])
            ->assertStatus(422)->assertJsonValidationErrors('key');

        $this->putJson("/api/programs/{$program->id}/fields/{$field->id}", ['type' => 'text'])
            ->assertStatus(422)->assertJsonValidationErrors('type');

        $this->putJson("/api/programs/{$program->id}/fields/{$field->id}", ['is_required' => true])
            ->assertStatus(422)->assertJsonValidationErrors('is_required');

        $this->putJson("/api/programs/{$program->id}/fields/{$field->id}", ['validation_rules' => ['min' => 5]])
            ->assertStatus(422)->assertJsonValidationErrors('validation_rules');
    }

    public function test_label_and_help_text_can_still_change_once_the_program_has_a_registration(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $field = ProgramField::factory()->create(['program_id' => $program->id]);
        $this->registerSomeoneFor($program);

        $this->putJson("/api/programs/{$program->id}/fields/{$field->id}", [
            'label' => 'New label', 'help_text' => 'New hint',
        ])->assertOk()
            ->assertJsonPath('data.label', 'New label')
            ->assertJsonPath('data.help_text', 'New hint');
    }

    public function test_removing_an_existing_option_is_rejected_but_adding_a_new_one_is_allowed(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $field = ProgramField::factory()->create([
            'program_id' => $program->id, 'type' => 'select', 'options' => ['Red', 'Blue'],
        ]);
        $this->registerSomeoneFor($program);

        $this->putJson("/api/programs/{$program->id}/fields/{$field->id}", ['options' => ['Red']])
            ->assertStatus(422)->assertJsonValidationErrors('options');

        $this->putJson("/api/programs/{$program->id}/fields/{$field->id}", ['options' => ['Red', 'Blue', 'Green']])
            ->assertOk()
            ->assertJsonPath('data.options', ['Red', 'Blue', 'Green']);
    }

    public function test_a_field_cannot_be_deleted_once_the_program_has_a_registration(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        $field = ProgramField::factory()->create(['program_id' => $program->id]);
        $this->registerSomeoneFor($program, Registration::STATUS_CANCELED);

        $this->deleteJson("/api/programs/{$program->id}/fields/{$field->id}")->assertStatus(422);
        $this->assertDatabaseHas('program_fields', ['id' => $field->id]);
    }

    public function test_capacity_cannot_drop_below_current_confirmed_registrations(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create(['capacity' => 10]);
        $this->registerSomeoneFor($program);
        $this->registerSomeoneFor($program);
        $this->registerSomeoneFor($program, Registration::STATUS_CANCELED);

        $this->putJson("/api/programs/{$program->id}", ['capacity' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('capacity');

        $this->putJson("/api/programs/{$program->id}", ['capacity' => 2])->assertOk();
    }

    public function test_registration_end_date_cannot_be_moved_into_the_past(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $this->putJson("/api/programs/{$program->id}", [
            'registration_ends_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('registration_ends_at');
    }

    public function test_registration_start_date_cannot_be_postponed_after_it_already_started(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create([
            'registration_starts_at' => now()->subDay(),
        ]);

        $this->putJson("/api/programs/{$program->id}", [
            'registration_starts_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('registration_starts_at');
    }

    public function test_registration_start_date_can_still_move_earlier_before_it_started(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create([
            'registration_starts_at' => now()->addDays(5),
        ]);

        $this->putJson("/api/programs/{$program->id}", [
            'registration_starts_at' => now()->addHours(2)->toIso8601String(),
        ])->assertOk();
    }
}
