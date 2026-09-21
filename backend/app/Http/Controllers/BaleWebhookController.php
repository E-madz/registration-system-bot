<?php

namespace App\Http\Controllers;

use App\Services\BotConversationService;
use App\Services\Messengers\BaleAdapter;
use Illuminate\Http\Request;

class BaleWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, BotConversationService $conversation)
    {
        if ($secret !== config('services.bale.webhook_secret')) {
            abort(404);
        }

        $adapter = new BaleAdapter(config('services.bale.bot_token'));
        $update = $adapter->parseUpdate($request);

        $conversation->handle($update, $adapter);

        return response()->json(['ok' => true]);
    }
}
