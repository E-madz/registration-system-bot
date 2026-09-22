<?php

namespace App\Services\Messengers;

use App\Contracts\MessengerAdapter;
use InvalidArgumentException;

/**
 * The webhook controllers each build their own adapter directly, since they
 * only ever need the one for the platform the request came in on. Anything
 * that needs to send a message OUTSIDE of a reply-to-inbound context (e.g.
 * notifications) doesn't have that luxury - it needs to resolve the right
 * adapter for an arbitrary BotUser's platform, hence this factory.
 */
class MessengerAdapterFactory
{
    public function make(string $platform): MessengerAdapter
    {
        return match ($platform) {
            'telegram' => new TelegramAdapter(config('services.telegram.bot_token')),
            'bale' => new BaleAdapter(config('services.bale.bot_token')),
            'rubika' => new RubikaAdapter(config('services.rubika.bot_token')),
            default => throw new InvalidArgumentException("Unknown messenger platform: {$platform}"),
        };
    }
}
