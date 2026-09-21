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
     * Send a text message, optionally with rows of inline buttons.
     *
     * @param  array<int, array<int, \App\DataTransferObjects\KeyboardButton>>|null  $keyboard
     */
    public function sendMessage(string $chatId, string $text, ?array $keyboard = null): void;

    /**
     * Acknowledge a callback/button press so the client stops showing a loading state.
     */
    public function answerCallback(string $callbackQueryId, ?string $text = null): void;
}
