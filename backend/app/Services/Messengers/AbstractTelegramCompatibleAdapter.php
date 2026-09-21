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
 * Base for messengers whose Bot API is a Telegram-Bot-API-compatible clone
 * (currently Telegram itself and Bale). Only the platform name and API base
 * URL differ between them.
 */
abstract class AbstractTelegramCompatibleAdapter implements MessengerAdapter
{
    use GuessesFileExtension;

    private bool $blockedByUser = false;

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
        $fileSize = null;

        if (isset($message['photo'])) {
            // Multiple sizes are sent; the last one is the largest.
            $largestPhoto = last($message['photo']);
            $fileId = $largestPhoto['file_id'] ?? null;
            $fileType = 'photo';
            $fileSize = $largestPhoto['file_size'] ?? null;
        } elseif (isset($message['document'])) {
            $fileId = $message['document']['file_id'] ?? null;
            $fileType = 'document';
            $fileSize = $message['document']['file_size'] ?? null;
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
            fileSize: $fileSize,
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
                $this->checkBlockedByUser($response);
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

    public function wasBlockedByUser(): bool
    {
        return $this->blockedByUser;
    }

    /**
     * Downloads a file the user sent us and stores it on the public disk so
     * the admin panel can serve it back, since a bare file_id is only ever
     * resolvable through this same bot token and expires eventually.
     * Returns the stored relative path, or null if anything failed.
     */
    public function downloadFile(string $fileId, string $fileType): ?string
    {
        try {
            $response = Http::timeout(10)->get($this->apiUrl('getFile'), ['file_id' => $fileId]);

            if ($response->failed()) {
                Log::warning($this->platform().' getFile failed', ['response' => $response->json()]);

                return null;
            }

            $filePath = $response->json('result.file_path');

            if (! $filePath) {
                return null;
            }

            $fileResponse = Http::timeout(20)->get("{$this->fileBaseUrl()}{$this->botToken}/{$filePath}");

            if ($fileResponse->failed()) {
                Log::warning($this->platform().' file download failed', ['file_path' => $filePath]);

                return null;
            }

            $extension = $this->guessExtension($filePath, $fileResponse->header('Content-Type'), $fileResponse->body());
            $storedPath = 'registration-uploads/'.$this->platform().'/'.Str::uuid().'.'.$extension;
            Storage::disk('public')->put($storedPath, $fileResponse->body());

            return $storedPath;
        } catch (ConnectionException $exception) {
            Log::error($this->platform().' downloadFile connection failed', ['message' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * Base URL for downloading an already-resolved file path, e.g.
     * "https://api.telegram.org/file/bot".
     */
    abstract protected function fileBaseUrl(): string;

    /**
     * Telegram (and Bale, its API clone) report a user having blocked the
     * bot as an HTTP-level failure: {"ok":false,"error_code":403,
     * "description":"Forbidden: bot was blocked by the user"}.
     */
    private function checkBlockedByUser(\Illuminate\Http\Client\Response $response): void
    {
        if ($response->status() === 403 && str_contains(strtolower($response->json('description') ?? ''), 'blocked')) {
            $this->blockedByUser = true;
        }
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
                $this->checkBlockedByUser($response);
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
