<?php

namespace App\Services\Messengers;

use App\Contracts\MessengerAdapter;
use App\DataTransferObjects\IncomingUpdate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramAdapter implements MessengerAdapter
{
    public function __construct(
        private readonly string $botToken,
    ) {}

    public function platform(): string
    {
        return 'telegram';
    }

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
                username: $callback['from']['username'] ?? null,
                callbackData: $callback['data'] ?? null,
                callbackQueryId: (string) $callback['id'],
            );
        }

        $message = $data['message'] ?? [];

        return new IncomingUpdate(
            platform: $this->platform(),
            chatId: (string) ($message['chat']['id'] ?? ''),
            externalUserId: (string) ($message['from']['id'] ?? ''),
            firstName: $message['from']['first_name'] ?? null,
            username: $message['from']['username'] ?? null,
            text: $message['text'] ?? null,
        );
    }

    public function sendMessage(string $chatId, string $text, ?array $keyboard = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($keyboard) {
            $payload['reply_markup'] = [
                'inline_keyboard' => array_map(
                    fn (array $row) => array_map(
                        fn ($button) => ['text' => $button->label, 'callback_data' => $button->value],
                        $row,
                    ),
                    $keyboard,
                ),
            ];
        }

        $this->post('sendMessage', $payload);
    }

    public function answerCallback(string $callbackQueryId, ?string $text = null): void
    {
        $this->post('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]));
    }

    /**
     * Call the Telegram API, swallowing network/connection failures so a
     * temporarily unreachable Telegram (e.g. server-side filtering) never
     * turns into a 500 on our webhook — we just log and move on.
     */
    private function post(string $method, array $payload): void
    {
        try {
            $response = Http::timeout(5)->post($this->apiUrl($method), $payload);

            if ($response->failed()) {
                Log::warning("Telegram {$method} failed", ['response' => $response->json()]);
            }
        } catch (ConnectionException $exception) {
            Log::error("Telegram {$method} connection failed", ['message' => $exception->getMessage()]);
        }
    }

    private function apiUrl(string $method): string
    {
        return "https://api.telegram.org/bot{$this->botToken}/{$method}";
    }
}
