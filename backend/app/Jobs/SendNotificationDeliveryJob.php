<?php

namespace App\Jobs;

use App\DataTransferObjects\KeyboardButton;
use App\Models\NotificationDelivery;
use App\Models\Registration;
use App\Services\Messengers\MessengerAdapterFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendNotificationDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $deliveryId) {}

    public function handle(MessengerAdapterFactory $adapterFactory): void
    {
        $delivery = NotificationDelivery::with(['notification', 'botUser'])->find($this->deliveryId);

        // Already processed (e.g. a retried job racing a prior attempt) or the
        // recipient/notification vanished - nothing left to do.
        if (! $delivery || $delivery->status !== NotificationDelivery::STATUS_PENDING) {
            return;
        }

        $notification = $delivery->notification;
        $botUser = $delivery->botUser;

        $adapter = $adapterFactory->make($delivery->platform);

        $keyboard = null;
        if ($notification->button_label && $notification->button_program_id) {
            $keyboard = [[new KeyboardButton($notification->button_label, "program-{$notification->button_program_id}")]];
        }

        try {
            $accepted = $adapter->sendMessage($botUser->chat_id, $notification->message, $keyboard);

            if ($adapter->wasBlockedByUser()) {
                $botUser->update(['is_blocked' => true, 'blocked_at' => now()]);
                $botUser->cancelAllConfirmedRegistrations(Registration::REASON_BOT_BLOCKED);

                $delivery->update(['status' => NotificationDelivery::STATUS_FAILED, 'error_message' => 'کاربر بات را مسدود کرده است.']);
                $notification->increment('failed_count');
            } elseif (! $accepted) {
                $delivery->update(['status' => NotificationDelivery::STATUS_FAILED, 'error_message' => 'پیام‌رسان درخواست را رد کرد.']);
                $notification->increment('failed_count');
            } else {
                $delivery->update(['status' => NotificationDelivery::STATUS_SENT, 'sent_at' => now()]);
                $notification->increment('sent_count');
            }
        } catch (Throwable $e) {
            $delivery->update(['status' => NotificationDelivery::STATUS_FAILED, 'error_message' => $e->getMessage()]);
            $notification->increment('failed_count');
        }

        $notification->fresh()->maybeMarkCompleted();
    }
}
