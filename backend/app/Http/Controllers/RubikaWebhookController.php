<?php

namespace App\Http\Controllers;

use App\Services\BotConversationService;
use App\Services\Messengers\RubikaAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RubikaWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, BotConversationService $conversation)
    {
        if ($secret !== config('services.rubika.webhook_secret')) {
            abort(404);
        }

        // Temporary: keeping this until inline_keypad delivery is confirmed
        // fixed. Logs both the raw payload and how we parsed it, so a
        // mismatch between the two is visible directly.
        Log::debug('Rubika raw update', $request->all());

        $adapter = new RubikaAdapter(config('services.rubika.bot_token'));
        $update = $adapter->parseUpdate($request);

        Log::debug('Rubika parsed update', (array) $update);

        $conversation->handle($update, $adapter);

        return response()->json(['ok' => true]);
    }
}
