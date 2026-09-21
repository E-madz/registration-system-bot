<?php

namespace App\Http\Controllers;

use App\Services\BotConversationService;
use App\Services\Messengers\RubikaAdapter;
use Illuminate\Http\Request;

class RubikaWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, BotConversationService $conversation)
    {
        if ($secret !== config('services.rubika.webhook_secret')) {
            abort(404);
        }

        $adapter = new RubikaAdapter(config('services.rubika.bot_token'));
        $update = $adapter->parseUpdate($request);

        $conversation->handle($update, $adapter);

        return response()->json(['ok' => true]);
    }
}
