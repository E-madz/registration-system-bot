<?php

namespace App\DataTransferObjects;

final class IncomingUpdate
{
    public function __construct(
        public readonly string $platform,
        public readonly string $chatId,
        public readonly string $externalUserId,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $username = null,
        public readonly ?string $text = null,
        public readonly ?string $callbackData = null,
        public readonly ?string $callbackQueryId = null,
    ) {}

    public function isCallback(): bool
    {
        return $this->callbackData !== null;
    }
}
