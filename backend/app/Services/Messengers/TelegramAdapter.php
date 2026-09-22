<?php

namespace App\Services\Messengers;

class TelegramAdapter extends AbstractTelegramCompatibleAdapter
{
    public function platform(): string
    {
        return 'telegram';
    }

    protected function apiBaseUrl(): string
    {
        return 'https://api.telegram.org/bot';
    }

    protected function fileBaseUrl(): string
    {
        return 'https://api.telegram.org/file/bot';
    }

    protected function proxyUrl(): ?string
    {
        return config('services.telegram.proxy');
    }
}
