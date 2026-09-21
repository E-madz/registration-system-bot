<?php

namespace App\Services\Messengers;

/**
 * Bale's Bot API (https://tapi.bale.ai) is intentionally a Telegram Bot API
 * clone, so this adapter only needs to override the platform name and the
 * API base URL.
 */
class BaleAdapter extends AbstractTelegramCompatibleAdapter
{
    public function platform(): string
    {
        return 'bale';
    }

    protected function apiBaseUrl(): string
    {
        return 'https://tapi.bale.ai/bot';
    }
}
