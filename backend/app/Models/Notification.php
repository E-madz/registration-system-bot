<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AUTO_BAN = 'auto_ban';

    public const SOURCE_AUTO_UNBAN = 'auto_unban';

    public const SOURCE_AUTO_PROGRAM_CANCELED = 'auto_program_canceled';

    public const SOURCE_AUTO_PROGRAM_PUBLISHED = 'auto_program_published';

    public const SOURCE_AUTO_REGISTRATION_CANCELED = 'auto_registration_canceled';

    public const AUDIENCE_PROGRAM_REGISTRANTS = 'program_registrants';

    public const AUDIENCE_ALL_USERS = 'all_users';

    public const AUDIENCE_INACTIVE_USERS = 'inactive_users';

    public const AUDIENCE_SINGLE_USER = 'single_user';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'message',
        'button_label',
        'button_program_id',
        'source',
        'audience_type',
        'audience_program_id',
        'audience_bot_user_id',
        'audience_platform',
        'inactive_days',
        'status',
        'total_recipients',
        'sent_count',
        'failed_count',
        'skipped_banned_count',
        'skipped_blocked_count',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function buttonProgram()
    {
        return $this->belongsTo(Program::class, 'button_program_id');
    }

    public function audienceProgram()
    {
        return $this->belongsTo(Program::class, 'audience_program_id');
    }

    public function audienceBotUser()
    {
        return $this->belongsTo(BotUser::class, 'audience_bot_user_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries()
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    /**
     * Flips status to completed once no delivery is left pending. Called
     * both right after creation (covers the case where every recipient was
     * skipped and no job was ever dispatched) and by the last job of a
     * batch to finish.
     */
    public function maybeMarkCompleted(): void
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return;
        }

        $stillPending = $this->deliveries()->where('status', NotificationDelivery::STATUS_PENDING)->exists();

        if (! $stillPending) {
            $this->update(['status' => self::STATUS_COMPLETED, 'completed_at' => now()]);
        }
    }
}
