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

        // Temporary again: re-checking ground truth after the '-' delimiter
        // fix didn't fully resolve tap delivery.
        Log::debug('Rubika raw update', $request->all());

        $adapter = new RubikaAdapter(config('services.rubika.bot_token'));
        $update = $adapter->parseUpdate($request);

        $conversation->handle($update, $adapter);

        return response()->json(['ok' => true]);
    }
}
