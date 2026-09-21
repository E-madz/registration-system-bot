<?php

namespace App\Http\Controllers;

use App\Services\BotConversationService;
use App\Services\Messengers\TelegramAdapter;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, BotConversationService $conversation)
    {
        if ($secret !== config('services.telegram.webhook_secret')) {
            abort(404);
        }

        $adapter = new TelegramAdapter(config('services.telegram.bot_token'));
        $update = $adapter->parseUpdate($request);

        $conversation->handle($update, $adapter);

        return response()->json(['ok' => true]);
    }
}
