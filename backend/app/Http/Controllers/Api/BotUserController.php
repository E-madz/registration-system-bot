<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BotUserResource;
use App\Models\BotUser;
use App\Models\Notification;
use App\Models\Registration;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class BotUserController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request)
    {
        $botUsers = BotUser::query()
            ->when($request->filled('platform'), fn ($query) => $query->where('platform', $request->input('platform')))
            ->when($request->filled('is_banned'), fn ($query) => $query->where('is_banned', $request->boolean('is_banned')))
            ->when($request->filled('is_blocked'), fn ($query) => $query->where('is_blocked', $request->boolean('is_blocked')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->input('search').'%';
                $query->where(function ($query) use ($term) {
                    $query->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('username', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('external_id', 'like', $term);
                });
            })
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return BotUserResource::collection($botUsers);
    }

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

        $message = $data['reason'] ?? null
            ? "شما توسط مدیریت مسدود شده‌اید و امکان استفاده از بات را ندارید.\nدلیل: {$data['reason']}"
            : 'شما توسط مدیریت مسدود شده‌اید و امکان استفاده از بات را ندارید.';

        $this->notifications->create([
            'message' => $message,
            'source' => Notification::SOURCE_AUTO_BAN,
            'audience_type' => Notification::AUDIENCE_SINGLE_USER,
            'audience_bot_user_id' => $botUser->id,
        ]);

        return new BotUserResource($botUser);
    }

    public function unban(BotUser $botUser)
    {
        $botUser->update([
            'is_banned' => false,
            'banned_at' => null,
            'ban_reason' => null,
        ]);

        $this->notifications->create([
            'message' => 'مسدودیت شما توسط مدیریت رفع شد و می‌توانید دوباره از بات استفاده کنید.',
            'source' => Notification::SOURCE_AUTO_UNBAN,
            'audience_type' => Notification::AUDIENCE_SINGLE_USER,
            'audience_bot_user_id' => $botUser->id,
        ]);

        return new BotUserResource($botUser);
    }
}
