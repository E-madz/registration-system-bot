<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\ProgramFieldController;
use App\Http\Controllers\TelegramTestWebhookController;
use Illuminate\Support\Facades\Route;

// Temporary connectivity check for Phase 0 — will be replaced by the real
// MessengerAdapter-based webhook handling in a later phase.
Route::post('/webhook/telegram/{secret}', TelegramTestWebhookController::class);

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::apiResource('programs', ProgramController::class);
    Route::patch('/programs/{program}/status', [ProgramController::class, 'updateStatus']);

    Route::get('/programs/{program}/fields', [ProgramFieldController::class, 'index']);
    Route::post('/programs/{program}/fields', [ProgramFieldController::class, 'store']);
    Route::post('/programs/{program}/fields/reorder', [ProgramFieldController::class, 'reorder']);
    Route::put('/programs/{program}/fields/{field}', [ProgramFieldController::class, 'update']);
    Route::delete('/programs/{program}/fields/{field}', [ProgramFieldController::class, 'destroy']);
});
