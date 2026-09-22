<?php

namespace App\Services\Messengers;

use App\Contracts\MessengerAdapter;
use App\DataTransferObjects\IncomingUpdate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Rubika's Bot API (https://botapi.rubika.ir/v3) has its own JSON shape,
 * unrelated to Telegram's. Key differences handled here:
 *  - chat_keypad (persistent reply keyboard) taps arrive as an ordinary
 *    `type: "NewMessage"` update, with `new_message.aux_data.button_id` set
 *    to the button's own label text.
 *  - inline_keypad (per-message button) taps arrive as a SEPARATE top-level
 *    shape, `{"inline_message": {...}}` (not nested under "update"), with
 *    `inline_message.aux_data.button_id` set to our action id. This needs a
 *    dedicated "ReceiveInlineMessage" endpoint registered via
 *    updateBotEndpoints (confirmed against two independent community
 *    libraries — Daniyel-Vanguard/Rubika-Bot (PHP) and Mahdy-Ahmadi/Rubka
 *    (Python) — after an earlier, wrong assumption here that both button
 *    kinds shared the `update.new_message` shape). That registration has so
 *    far always returned {"status":"OK","data":{"status":"InvalidUrl"}} in
 *    our testing, which is why inline_keypad taps don't reach us yet.
 *  - No callback-acknowledgement concept like Telegram's answerCallbackQuery,
 *    so answerCallback() is a no-op.
 *  - Sending a photo is a three-step dance: request an upload URL, upload
 *    the file there, then call sendFile with the returned file_id.
 */
class RubikaAdapter implements MessengerAdapter
{
    use GuessesFileExtension;


    private bool $blockedByUser = false;

    public function __construct(
        private readonly string $botToken,
    ) {}

    public function platform(): string
    {
        return 'rubika';
    }

    public function parseUpdate(Request $request): IncomingUpdate
    {
        $data = $request->all();

        if (isset($data['inline_message'])) {
            $inline = $data['inline_message'];

            return new IncomingUpdate(
                platform: $this->platform(),
                chatId: (string) ($inline['chat_id'] ?? ''),
                externalUserId: (string) ($inline['sender_id'] ?? ''),
                callbackData: $inline['aux_data']['button_id'] ?? null,
                callbackQueryId: (string) ($inline['message_id'] ?? uniqid('rubika_', true)),
            );
        }

        $update = $data['update'] ?? [];
        $message = $update['new_message'] ?? [];
        $buttonId = $message['aux_data']['button_id'] ?? null;

        return new IncomingUpdate(
            platform: $this->platform(),
            chatId: (string) ($update['chat_id'] ?? ''),
            externalUserId: (string) ($message['sender_id'] ?? ''),
            text: $buttonId ?? ($message['text'] ?? null),
            fileId: $message['file']['file_id'] ?? null,
            fileType: isset($message['file']) ? 'file' : null,
            fileSize: $message['file']['size'] ?? null,
        );
    }

    public function sendMessage(string $chatId, string $text, ?array $keyboard = null): bool
    {
        $payload = ['chat_id' => $chatId, 'text' => $text];

        if ($keyboard) {
            $payload['inline_keypad'] = $this->buildInlineKeypad($keyboard);
        }

        return $this->post('sendMessage', $payload);
    }

    public function sendPhoto(string $chatId, string $photoPath, string $caption, ?array $keyboard = null): void
    {
        try {
            $uploadUrlResponse = Http::timeout(10)->post($this->apiUrl('requestSendFile'), ['type' => 'Image']);

            if ($uploadUrlResponse->failed()) {
                Log::warning('Rubika requestSendFile failed', ['response' => $uploadUrlResponse->json()]);

                return;
            }

            $uploadUrl = $uploadUrlResponse->json('data.upload_url');

            $uploadResponse = Http::timeout(15)
                ->attach('file', file_get_contents($photoPath), basename($photoPath))
                ->post($uploadUrl);

            $fileId = $uploadResponse->json('data.file_id');

            if (! $fileId) {
                Log::warning('Rubika file upload failed', ['response' => $uploadResponse->json()]);

                return;
            }

            $payload = ['chat_id' => $chatId, 'file_id' => $fileId, 'type' => 'Image', 'text' => $caption];

            if ($keyboard) {
                $payload['inline_keypad'] = $this->buildInlineKeypad($keyboard);
            }

            $this->post('sendFile', $payload);
        } catch (ConnectionException $exception) {
            Log::error('Rubika sendPhoto connection failed', ['message' => $exception->getMessage()]);
        }
    }

    public function sendReplyKeyboard(string $chatId, string $text, array $buttonRows): void
    {
        $rows = array_map(
            fn (array $row) => ['buttons' => array_map(
                fn (string $label) => ['id' => $label, 'type' => 'Simple', 'button_text' => $label],
                $row,
            )],
            $buttonRows,
        );

        $this->post('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'chat_keypad_type' => 'New',
            'chat_keypad' => ['rows' => $rows, 'resize_keyboard' => true, 'on_time_keyboard' => false],
        ]);
    }

    public function answerCallback(string $callbackQueryId, ?string $text = null): void
    {
        // Rubika has no equivalent of Telegram's "answer this callback query"
        // acknowledgement — button taps arrive as ordinary messages/inline
        // updates with no client-side loading state to clear.
    }

    /**
     * Best-effort only: unlike Telegram, Rubika's documentation doesn't
     * describe what a "user blocked the bot" response looks like, and we've
     * never actually encountered one in testing. This matches a status
     * string containing "block" as a guess; if that never fires in
     * practice, this will need revisiting once we actually see one.
     */
    public function wasBlockedByUser(): bool
    {
        return $this->blockedByUser;
    }

    /**
     * Rubika's getFile POST returns {"data":{"download_url": "..."}}
     * (confirmed against the Mahdy-Ahmadi/Rubka Python library, since this
     * isn't documented); a plain GET on that URL then returns the bytes.
     */
    public function downloadFile(string $fileId, string $fileType): ?string
    {
        try {
            $response = Http::timeout(10)->post($this->apiUrl('getFile'), ['file_id' => $fileId]);

            if ($response->failed()) {
                Log::warning('Rubika getFile failed', ['response' => $response->json()]);

                return null;
            }

            $downloadUrl = $response->json('data.download_url');

            if (! $downloadUrl) {
                return null;
            }

            $fileResponse = Http::timeout(20)->get($downloadUrl);

            if ($fileResponse->failed()) {
                Log::warning('Rubika file download failed', ['url' => $downloadUrl]);

                return null;
            }

            $extension = $this->guessExtension(parse_url($downloadUrl, PHP_URL_PATH) ?: null, $fileResponse->header('Content-Type'), $fileResponse->body());
            $storedPath = 'registration-uploads/rubika/'.Str::uuid().'.'.$extension;
            Storage::disk('public')->put($storedPath, $fileResponse->body());

            return $storedPath;
        } catch (ConnectionException $exception) {
            Log::error('Rubika downloadFile connection failed', ['message' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<int, array<int, \App\DataTransferObjects\KeyboardButton>>  $keyboard
     */
    private function buildInlineKeypad(array $keyboard): array
    {
        $rows = array_map(
            fn (array $row) => ['buttons' => array_map(
                fn ($button) => ['id' => $button->value, 'type' => 'Simple', 'button_text' => $button->label],
                $row,
            )],
            $keyboard,
        );

        return ['rows' => $rows];
    }

    private function post(string $method, array $payload): bool
    {
        try {
            $response = Http::timeout(5)->post($this->apiUrl($method), $payload);

            // Temporary: Rubika returns HTTP 200 even when it rejects the
            // request (the real outcome is nested in the JSON body, e.g.
            // {"status":"OK","data":{"status":"InvalidInput", ...}}), so
            // $response->failed() alone can't catch a rejected sendMessage.
            // Logging the full body until we've confirmed what a rejection
            // actually looks like for inline_keypad payloads.
            $body = $response->json();
            $innerStatus = $body['data']['status'] ?? $body['status'] ?? null;

            if ($response->failed() || ($innerStatus !== null && $innerStatus !== 'OK')) {
                if ($innerStatus !== null && str_contains(strtolower($innerStatus), 'block')) {
                    $this->blockedByUser = true;
                }
                Log::warning("Rubika {$method} rejected", ['payload' => $payload, 'response' => $body]);

                return false;
            }

            return true;
        } catch (ConnectionException $exception) {
            Log::error("Rubika {$method} connection failed", ['message' => $exception->getMessage()]);

            return false;
        }
    }

    private function apiUrl(string $method): string
    {
        return "https://botapi.rubika.ir/v3/{$this->botToken}/{$method}";
    }
}
