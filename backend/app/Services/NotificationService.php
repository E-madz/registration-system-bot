<?php

namespace App\Services;

use App\Jobs\SendNotificationDeliveryJob;
use App\Models\BotUser;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class NotificationService
{
    /**
     * Milliseconds between each dispatched job, staggering delivery so we
     * don't hammer any platform's API all at once.
     */
    private const DISPATCH_STAGGER_MS = 150;

    public function create(array $attributes): Notification
    {
        $recipients = $this->resolveAudience($attributes);

        $notification = Notification::create([
            ...$attributes,
            'total_recipients' => $recipients->count(),
        ]);

        // Only the single_user audience (used for ban/unban notices) is
        // allowed to reach an already-banned user - every other audience
        // type skips banned/blocked users rather than trying and failing.
        $bypassBanBlockFilter = $notification->audience_type === Notification::AUDIENCE_SINGLE_USER;

        foreach ($recipients as $index => $botUser) {
            if (! $bypassBanBlockFilter && $botUser->is_banned) {
                $this->recordSkip($notification, $botUser, NotificationDelivery::STATUS_SKIPPED_BANNED);
                $notification->increment('skipped_banned_count');

                continue;
            }

            if (! $bypassBanBlockFilter && $botUser->is_blocked) {
                $this->recordSkip($notification, $botUser, NotificationDelivery::STATUS_SKIPPED_BLOCKED);
                $notification->increment('skipped_blocked_count');

                continue;
            }

            $delivery = NotificationDelivery::create([
                'notification_id' => $notification->id,
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
                'status' => NotificationDelivery::STATUS_PENDING,
            ]);

            SendNotificationDeliveryJob::dispatch($delivery->id)
                ->delay(now()->addMilliseconds($index * self::DISPATCH_STAGGER_MS));
        }

        $notification->fresh()->maybeMarkCompleted();

        return $notification->fresh();
    }

    /**
     * @return Collection<int, BotUser>
     */
    public function resolveAudience(array $attributes): Collection
    {
        $platform = $attributes['audience_platform'] ?? null;

        return match ($attributes['audience_type'] ?? null) {
            Notification::AUDIENCE_PROGRAM_REGISTRANTS => BotUser::query()
                ->whereHas('registrations', function ($query) use ($attributes) {
                    $query->where('program_id', $this->required($attributes, 'audience_program_id'))
                        ->where('status', Registration::STATUS_CONFIRMED);
                })
                ->when($platform, fn ($query) => $query->where('platform', $platform))
                ->get(),

            Notification::AUDIENCE_ALL_USERS => BotUser::query()
                ->when($platform, fn ($query) => $query->where('platform', $platform))
                ->get(),

            Notification::AUDIENCE_INACTIVE_USERS => BotUser::query()
                ->where(function ($query) use ($attributes) {
                    $days = $this->required($attributes, 'inactive_days');
                    $query->whereNull('last_interaction_at')
                        ->orWhere('last_interaction_at', '<', now()->subDays($days));
                })
                ->when($platform, fn ($query) => $query->where('platform', $platform))
                ->get(),

            Notification::AUDIENCE_SINGLE_USER => BotUser::query()
                ->where('id', $this->required($attributes, 'audience_bot_user_id'))
                ->get(),

            default => throw new InvalidArgumentException('Unknown audience_type.'),
        };
    }

    private function recordSkip(Notification $notification, BotUser $botUser, string $status): void
    {
        NotificationDelivery::create([
            'notification_id' => $notification->id,
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'status' => $status,
        ]);
    }

    private function required(array $attributes, string $key): mixed
    {
        if (empty($attributes[$key])) {
            throw new InvalidArgumentException("Missing required attribute [{$key}] for this audience_type.");
        }

        return $attributes[$key];
    }
}
