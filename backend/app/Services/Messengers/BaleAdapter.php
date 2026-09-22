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

    /**
     * Unconfirmed: mirrors Telegram's file-download convention since Bale's
     * API is documented as an intentional clone, but this specific endpoint
     * has never actually been exercised against Bale.
     */
    protected function fileBaseUrl(): string
    {
        return 'https://tapi.bale.ai/file/bot';
    }
}
