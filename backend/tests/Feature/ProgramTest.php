<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_rejects_unauthenticated_access(): void
    {
        $this->getJson('/api/programs')->assertStatus(401);
    }

    public function test_creates_a_program_with_an_auto_generated_slug(): void
    {
        $this->actingAdmin();

        $response = $this->postJson('/api/programs', [
            'title' => 'Summer Camp',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.slug', 'summer-camp');
        $response->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('programs', ['title' => 'Summer Camp', 'slug' => 'summer-camp']);
    }

    public function test_generates_unique_slugs_for_duplicate_titles(): void
    {
        $this->actingAdmin();

        $this->postJson('/api/programs', ['title' => 'Summer Camp'])->assertCreated();
        $response = $this->postJson('/api/programs', ['title' => 'Summer Camp'])->assertCreated();

        $response->assertJsonPath('data.slug', 'summer-camp-2');
    }

    public function test_adds_fields_to_a_program(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $response = $this->postJson("/api/programs/{$program->id}/fields", [
            'label' => 'Phone number',
            'key' => 'phone_number',
            'type' => 'phone',
            'is_required' => true,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('program_fields', [
            'program_id' => $program->id,
            'key' => 'phone_number',
        ]);
    }

    public function test_rejects_duplicate_field_keys_within_the_same_program(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $this->postJson("/api/programs/{$program->id}/fields", [
            'label' => 'Phone', 'key' => 'phone', 'type' => 'phone',
        ])->assertCreated();

        $this->postJson("/api/programs/{$program->id}/fields", [
            'label' => 'Phone again', 'key' => 'phone', 'type' => 'phone',
        ])->assertStatus(422);
    }

    public function test_enforces_the_status_transition_matrix(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create(['status' => Program::STATUS_DRAFT]);

        $this->patchJson("/api/programs/{$program->id}/status", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->patchJson("/api/programs/{$program->id}/status", ['status' => 'draft'])
            ->assertStatus(422);
    }

    public function test_only_allows_hard_deleting_draft_programs(): void
    {
        $this->actingAdmin();

        $draft = Program::factory()->create(['status' => Program::STATUS_DRAFT]);
        $published = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);

        $this->deleteJson("/api/programs/{$draft->id}")->assertStatus(204);
        $this->deleteJson("/api/programs/{$published->id}")->assertStatus(422);
    }
}
