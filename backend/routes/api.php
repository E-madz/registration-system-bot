<?php

use App\Http\Controllers\TelegramTestWebhookController;
use Illuminate\Support\Facades\Route;

// Temporary connectivity check for Phase 0 — will be replaced by the real
// MessengerAdapter-based webhook handling in a later phase.
Route::post('/webhook/telegram/{secret}', TelegramTestWebhookController::class);
