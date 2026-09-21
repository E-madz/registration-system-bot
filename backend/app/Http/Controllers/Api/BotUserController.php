<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BotUserResource;
use App\Models\BotUser;
use App\Models\Registration;
use Illuminate\Http\Request;

class BotUserController extends Controller
{
    public function ban(Request $request, BotUser $botUser)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $botUser->update([
            'is_banned' => true,
            'banned_at' => now(),
            'ban_reason' => $data['reason'] ?? null,
        ]);

        $botUser->cancelAllConfirmedRegistrations(Registration::REASON_BANNED);

        return new BotUserResource($botUser);
    }

    public function unban(BotUser $botUser)
    {
        $botUser->update([
            'is_banned' => false,
            'banned_at' => null,
            'ban_reason' => null,
        ]);

        return new BotUserResource($botUser);
    }
}
