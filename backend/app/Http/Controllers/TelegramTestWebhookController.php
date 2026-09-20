<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Temporary connectivity check for the Telegram webhook path (ngrok -> Laravel).
 * Not part of the real bot architecture — remove once Phase 2 introduces
 * the MessengerAdapter-based webhook handling.
 */
class TelegramTestWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret)
    {
        if ($secret !== config('services.telegram.webhook_secret')) {
            abort(404);
        }

        Log::info('Telegram webhook received', $request->all());

        return response()->json(['ok' => true]);
    }
}
