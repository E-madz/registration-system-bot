<?php

namespace App\Contracts;

use App\DataTransferObjects\IncomingUpdate;
use Illuminate\Http\Request;

interface MessengerAdapter
{
    /**
     * The platform identifier this adapter handles, e.g. "telegram".
     */
    public function platform(): string;

    /**
     * Normalize a raw webhook request into a platform-agnostic update.
     */
    public function parseUpdate(Request $request): IncomingUpdate;

    /**
     * Send a text message, optionally with rows of inline buttons. Returns
     * whether the platform accepted the message - false covers any rejection
     * other than being blocked (invalid chat id, rate limiting, etc.), which
     * notification delivery tracking needs in order to report real failures
     * instead of just logging them and moving on.
     *
     * @param  array<int, array<int, \App\DataTransferObjects\KeyboardButton>>|null  $keyboard
     */
    public function sendMessage(string $chatId, string $text, ?array $keyboard = null): bool;

    /**
     * Send a locally stored image with a caption, optionally with rows of inline buttons.
     *
     * @param  array<int, array<int, \App\DataTransferObjects\KeyboardButton>>|null  $keyboard
     */
    public function sendPhoto(string $chatId, string $photoPath, string $caption, ?array $keyboard = null): void;

    /**
     * Send a message with a persistent (non-inline) reply keyboard made of plain text buttons.
     *
     * @param  array<int, array<int, string>>  $buttonRows
     */
    public function sendReplyKeyboard(string $chatId, string $text, array $buttonRows): void;

    /**
     * Acknowledge a callback/button press so the client stops showing a loading state.
     */
    public function answerCallback(string $callbackQueryId, ?string $text = null): void;

    /**
     * Whether any send call made on this adapter instance so far failed
     * because the recipient has blocked the bot on this platform. Stateful
     * per-instance - since each webhook request builds a fresh adapter, this
     * reflects only what happened during the current request.
     */
    public function wasBlockedByUser(): bool;

    /**
     * Downloads a file the user previously sent (identified by fileId/
     * fileType from an IncomingUpdate) and stores it locally so it stays
     * accessible from the admin panel. Returns the stored relative path
     * (on the "public" disk) on success, or null if it couldn't be
     * downloaded.
     */
    public function downloadFile(string $fileId, string $fileType): ?string;
}
