<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BotUserController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\ProgramFieldController;
use App\Http\Controllers\Api\RegistrationController;
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

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::apiResource('programs', ProgramController::class);
    Route::patch('/programs/{program}/status', [ProgramController::class, 'updateStatus']);
    Route::post('/programs/{program}/media', [ProgramController::class, 'uploadMedia']);
    Route::delete('/programs/{program}/media', [ProgramController::class, 'deleteMedia']);

    Route::get('/programs/{program}/fields', [ProgramFieldController::class, 'index']);
    Route::post('/programs/{program}/fields', [ProgramFieldController::class, 'store']);
    Route::post('/programs/{program}/fields/reorder', [ProgramFieldController::class, 'reorder']);
    Route::put('/programs/{program}/fields/{field}', [ProgramFieldController::class, 'update']);
    Route::delete('/programs/{program}/fields/{field}', [ProgramFieldController::class, 'destroy']);

    Route::get('/bot-users', [BotUserController::class, 'index']);
    Route::patch('/bot-users/{botUser}/ban', [BotUserController::class, 'ban']);
    Route::patch('/bot-users/{botUser}/unban', [BotUserController::class, 'unban']);

    Route::get('/programs/{program}/registrations', [RegistrationController::class, 'index']);
    Route::get('/programs/{program}/registrations/export', [RegistrationController::class, 'export']);
    Route::get('/programs/{program}/registrations/{registration}', [RegistrationController::class, 'show']);
    Route::patch('/programs/{program}/registrations/{registration}/cancel', [RegistrationController::class, 'cancel']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications', [NotificationController::class, 'store']);
    Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
});
