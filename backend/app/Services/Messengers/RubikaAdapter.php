<?php

namespace App\Services\Messengers;

use App\Contracts\MessengerAdapter;
use App\DataTransferObjects\IncomingUpdate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        );
    }

    public function sendMessage(string $chatId, string $text, ?array $keyboard = null): void
    {
        $payload = ['chat_id' => $chatId, 'text' => $text];

        if ($keyboard) {
            $payload['inline_keypad'] = $this->buildInlineKeypad($keyboard);
        }

        $this->post('sendMessage', $payload);
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

    private function post(string $method, array $payload): void
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
                Log::warning("Rubika {$method} rejected", ['payload' => $payload, 'response' => $body]);
            }
        } catch (ConnectionException $exception) {
            Log::error("Rubika {$method} connection failed", ['message' => $exception->getMessage()]);
        }
    }

    private function apiUrl(string $method): string
    {
        return "https://botapi.rubika.ir/v3/{$this->botToken}/{$method}";
    }
}
