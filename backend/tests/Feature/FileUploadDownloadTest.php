<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\ProgramField;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deliberately does NOT call the bare, no-argument Http::fake() anywhere:
 * that registers an unconditional "match everything" stub that - because
 * Http::fake() stubs are checked in registration order and the first match
 * wins - would shadow the specific getFile/file-download stubs these tests
 * need, no matter what's registered afterwards. Each test here fakes only
 * the exact URLs it cares about.
 */
class FileUploadDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret']);
        Storage::fake('public');
    }

    private function sendCallback(string $data): void
    {
        $this->postJson('/api/webhook/telegram/test-secret', [
            'callback_query' => [
                'id' => 'cbq-'.uniqid(),
                'from' => ['id' => 222, 'first_name' => 'Emad'],
                'message' => ['chat' => ['id' => 111]],
                'data' => $data,
            ],
        ])->assertOk();
    }

    private function sendPhoto(): void
    {
        $this->postJson('/api/webhook/telegram/test-secret', [
            'message' => [
                'chat' => ['id' => 111],
                'from' => ['id' => 222, 'first_name' => 'Emad'],
                'photo' => [
                    ['file_id' => 'large_file_id', 'width' => 800, 'height' => 800],
                ],
            ],
        ])->assertOk();
    }

    public function test_downloads_and_stores_the_uploaded_file(): void
    {
        Http::fake([
            'api.telegram.org/*/getFile*' => Http::response([
                'ok' => true, 'result' => ['file_path' => 'photos/file_1.jpg'],
            ]),
            'api.telegram.org/file/*' => Http::response('fake-image-bytes'),
            '*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'id_card', 'label' => 'ID card photo',
            'type' => 'file', 'is_required' => true, 'order' => 0,
        ]);

        $this->sendCallback("register-{$program->id}");
        $this->sendPhoto();
        $this->sendCallback('confirm_registration');

        $registration = Registration::first();
        $this->assertNotNull($registration);
        $answer = $registration->answers()->where('field_key', 'id_card')->first();
        $this->assertArrayHasKey('stored_path', $answer->value);
        Storage::disk('public')->assertExists($answer->value['stored_path']);
        $this->assertSame('fake-image-bytes', Storage::disk('public')->get($answer->value['stored_path']));
    }

    public function test_still_registers_successfully_when_the_download_itself_fails(): void
    {
        Http::fake([
            'api.telegram.org/*/getFile*' => Http::response(['ok' => false], 400),
            '*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $program = Program::factory()->create(['status' => Program::STATUS_PUBLISHED]);
        ProgramField::factory()->create([
            'program_id' => $program->id, 'key' => 'id_card', 'label' => 'ID card photo',
            'type' => 'file', 'is_required' => true, 'order' => 0,
        ]);

        $this->sendCallback("register-{$program->id}");
        $this->sendPhoto();
        $this->sendCallback('confirm_registration');

        $registration = Registration::first();
        $this->assertNotNull($registration);
        $answer = $registration->answers()->where('field_key', 'id_card')->first();
        $this->assertArrayNotHasKey('stored_path', $answer->value);
        $this->assertSame('large_file_id', $answer->value['file_id']);
    }
}
