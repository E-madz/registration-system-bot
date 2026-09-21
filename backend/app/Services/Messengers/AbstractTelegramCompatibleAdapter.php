<?php

namespace App\Services\Messengers;

use App\Contracts\MessengerAdapter;
use App\DataTransferObjects\IncomingUpdate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Base for messengers whose Bot API is a Telegram-Bot-API-compatible clone
 * (currently Telegram itself and Bale). Only the platform name and API base
 * URL differ between them.
 */
abstract class AbstractTelegramCompatibleAdapter implements MessengerAdapter
{
    public function __construct(
        protected readonly string $botToken,
    ) {}

    abstract public function platform(): string;

    abstract protected function apiBaseUrl(): string;

    public function parseUpdate(Request $request): IncomingUpdate
    {
        $data = $request->all();

        if (isset($data['callback_query'])) {
            $callback = $data['callback_query'];

            return new IncomingUpdate(
                platform: $this->platform(),
                chatId: (string) $callback['message']['chat']['id'],
                externalUserId: (string) $callback['from']['id'],
                firstName: $callback['from']['first_name'] ?? null,
                lastName: $callback['from']['last_name'] ?? null,
                username: $callback['from']['username'] ?? null,
                callbackData: $callback['data'] ?? null,
                callbackQueryId: (string) $callback['id'],
            );
        }

        $message = $data['message'] ?? [];

        $fileId = null;
        $fileType = null;

        if (isset($message['photo'])) {
            // Multiple sizes are sent; the last one is the largest.
            $fileId = last($message['photo'])['file_id'] ?? null;
            $fileType = 'photo';
        } elseif (isset($message['document'])) {
            $fileId = $message['document']['file_id'] ?? null;
            $fileType = 'document';
        }

        return new IncomingUpdate(
            platform: $this->platform(),
            chatId: (string) ($message['chat']['id'] ?? ''),
            externalUserId: (string) ($message['from']['id'] ?? ''),
            firstName: $message['from']['first_name'] ?? null,
            lastName: $message['from']['last_name'] ?? null,
            username: $message['from']['username'] ?? null,
            text: $message['text'] ?? null,
            fileId: $fileId,
            fileType: $fileType,
        );
    }

    public function sendMessage(string $chatId, string $text, ?array $keyboard = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($keyboard) {
            $payload['reply_markup'] = ['inline_keyboard' => $this->buildInlineKeyboard($keyboard)];
        }

        $this->post('sendMessage', $payload);
    }

    public function sendPhoto(string $chatId, string $photoPath, string $caption, ?array $keyboard = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'caption' => $caption,
        ];

        if ($keyboard) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $this->buildInlineKeyboard($keyboard)]);
        }

        try {
            $response = Http::timeout(10)
                ->attach('photo', file_get_contents($photoPath), basename($photoPath))
                ->post($this->apiUrl('sendPhoto'), $payload);

            if ($response->failed()) {
                Log::warning($this->platform().' sendPhoto failed', ['response' => $response->json()]);
            }
        } catch (ConnectionException $exception) {
            Log::error($this->platform().' sendPhoto connection failed', ['message' => $exception->getMessage()]);
        }
    }

    public function sendReplyKeyboard(string $chatId, string $text, array $buttonRows): void
    {
        $this->post('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => [
                'keyboard' => array_map(
                    fn (array $row) => array_map(fn (string $label) => ['text' => $label], $row),
                    $buttonRows,
                ),
                'resize_keyboard' => true,
            ],
        ]);
    }

    public function answerCallback(string $callbackQueryId, ?string $text = null): void
    {
        $this->post('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]));
    }

    /**
     * @param  array<int, array<int, \App\DataTransferObjects\KeyboardButton>>  $keyboard
     */
    private function buildInlineKeyboard(array $keyboard): array
    {
        return array_map(
            fn (array $row) => array_map(
                fn ($button) => ['text' => $button->label, 'callback_data' => $button->value],
                $row,
            ),
            $keyboard,
        );
    }

    /**
     * Call the API, swallowing network/connection failures so a temporarily
     * unreachable messenger never turns into a 500 on our webhook — we just
     * log and move on.
     */
    private function post(string $method, array $payload): void
    {
        try {
            $response = Http::timeout(5)->post($this->apiUrl($method), $payload);

            if ($response->failed()) {
                Log::warning($this->platform()." {$method} failed", ['response' => $response->json()]);
            }
        } catch (ConnectionException $exception) {
            Log::error($this->platform()." {$method} connection failed", ['message' => $exception->getMessage()]);
        }
    }

    private function apiUrl(string $method): string
    {
        return "{$this->apiBaseUrl()}{$this->botToken}/{$method}";
    }
}
