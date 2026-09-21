<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProgramMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function actingAdmin(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_uploads_an_image_for_a_program(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $response = $this->postJson("/api/programs/{$program->id}/media", [
            'media' => UploadedFile::fake()->image('poster.jpg'),
        ]);

        $response->assertOk();
        $program->refresh();
        Storage::disk('public')->assertExists($program->media_path);
        $this->assertNotNull($response->json('data.media_url'));
    }

    public function test_rejects_non_image_files(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $this->postJson("/api/programs/{$program->id}/media", [
            'media' => UploadedFile::fake()->create('resume.pdf', 100),
        ])->assertStatus(422);
    }

    public function test_replacing_media_deletes_the_old_file(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $this->postJson("/api/programs/{$program->id}/media", [
            'media' => UploadedFile::fake()->image('first.jpg'),
        ])->assertOk();
        $firstPath = $program->refresh()->media_path;

        $this->postJson("/api/programs/{$program->id}/media", [
            'media' => UploadedFile::fake()->image('second.jpg'),
        ])->assertOk();

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($program->refresh()->media_path);
    }

    public function test_deletes_media(): void
    {
        $this->actingAdmin();
        $program = Program::factory()->create();

        $this->postJson("/api/programs/{$program->id}/media", [
            'media' => UploadedFile::fake()->image('poster.jpg'),
        ])->assertOk();
        $path = $program->refresh()->media_path;

        $this->deleteJson("/api/programs/{$program->id}/media")->assertOk();

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($program->refresh()->media_path);
    }
}
