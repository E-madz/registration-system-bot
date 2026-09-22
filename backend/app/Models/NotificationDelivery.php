<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED_BANNED = 'skipped_banned';

    public const STATUS_SKIPPED_BLOCKED = 'skipped_blocked';

    protected $fillable = [
        'notification_id',
        'bot_user_id',
        'platform',
        'status',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }

    public function botUser()
    {
        return $this->belongsTo(BotUser::class);
    }
}
