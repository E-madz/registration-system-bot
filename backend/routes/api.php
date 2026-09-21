<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\ProgramFieldController;
use App\Http\Controllers\BaleWebhookController;
use App\Http\Controllers\RubikaWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/telegram/{secret}', TelegramWebhookController::class);
Route::post('/webhook/bale/{secret}', BaleWebhookController::class);
Route::post('/webhook/rubika/{secret}', RubikaWebhookController::class);

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::apiResource('programs', ProgramController::class);
    Route::patch('/programs/{program}/status', [ProgramController::class, 'updateStatus']);
    Route::post('/programs/{program}/media', [ProgramController::class, 'uploadMedia']);
    Route::delete('/programs/{program}/media', [ProgramController::class, 'deleteMedia']);

    Route::get('/programs/{program}/fields', [ProgramFieldController::class, 'index']);
    Route::post('/programs/{program}/fields', [ProgramFieldController::class, 'store']);
    Route::post('/programs/{program}/fields/reorder', [ProgramFieldController::class, 'reorder']);
    Route::put('/programs/{program}/fields/{field}', [ProgramFieldController::class, 'update']);
    Route::delete('/programs/{program}/fields/{field}', [ProgramFieldController::class, 'destroy']);
});
