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
 *  - In practice, BOTH chat_keypad (persistent reply keyboard) taps and
 *    inline_keypad (per-message button) taps arrive via the same
 *    `update.new_message` shape, with `aux_data.button_id` set to the
 *    button's id. Registering a separate "ReceiveInlineMessage" endpoint
 *    for a distinct `inline_message` shape returned "InvalidUrl" from
 *    Rubika and never actually delivered anything — that shape is kept
 *    below only as a harmless fallback in case it's ever needed.
 *  - Since both button kinds share one field, we tell them apart the only
 *    way we reliably can: our own inline button ids (built in
 *    buildKeypad()/BotConversationService) are always space-free
 *    identifiers like "program-5" or "back_to_programs", while our only
 *    chat_keypad buttons are the main-menu labels, which always contain a
 *    space. A button id with a space is therefore treated as if the user
 *    had typed that label as plain text; anything else is callback data.
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

        // See the class docblock: a button id containing a space is one of
        // our chat_keypad main-menu labels (treat as typed text); anything
        // else is one of our own inline_keypad action ids (treat as a
        // callback, same as Telegram's callback_data).
        $isChatKeypadLabel = $buttonId !== null && str_contains($buttonId, ' ');

        return new IncomingUpdate(
            platform: $this->platform(),
            chatId: (string) ($update['chat_id'] ?? ''),
            externalUserId: (string) ($message['sender_id'] ?? ''),
            text: $isChatKeypadLabel ? $buttonId : ($buttonId ? null : ($message['text'] ?? null)),
            callbackData: $isChatKeypadLabel ? null : $buttonId,
            callbackQueryId: (! $isChatKeypadLabel && $buttonId)
                ? (string) ($message['message_id'] ?? uniqid('rubika_', true))
                : null,
            fileId: $message['file']['file_id'] ?? null,
            fileType: isset($message['file']) ? 'file' : null,
        );
    }

    public function sendMessage(string $chatId, string $text, ?array $keyboard = null): void
    {
        $payload = ['chat_id' => $chatId, 'text' => $text];

        if ($keyboard) {
            $payload['inline_keypad'] = $this->buildKeypad($keyboard);
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
                $payload['inline_keypad'] = $this->buildKeypad($keyboard);
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
    private function buildKeypad(array $keyboard): array
    {
        $rows = array_map(
            fn (array $row) => ['buttons' => array_map(
                fn ($button) => ['id' => $button->value, 'type' => 'Simple', 'button_text' => $button->label],
                $row,
            )],
            $keyboard,
        );

        return ['rows' => $rows, 'resize_keyboard' => true, 'on_time_keyboard' => false];
    }

    private function post(string $method, array $payload): void
    {
        try {
            $response = Http::timeout(5)->post($this->apiUrl($method), $payload);

            if ($response->failed()) {
                Log::warning("Rubika {$method} failed", ['response' => $response->json()]);
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
