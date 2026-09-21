<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Registration extends Model
{
    use HasFactory;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELED = 'canceled';

    public const REASON_SELF = 'self';

    public const REASON_ADMIN = 'admin';

    public const REASON_BANNED = 'banned';

    public const REASON_BOT_BLOCKED = 'bot_blocked';

    protected $fillable = [
        'program_id',
        'bot_user_id',
        'status',
        'tracking_code',
        'canceled_at',
        'canceled_reason',
    ];

    protected function casts(): array
    {
        return [
            'canceled_at' => 'datetime',
        ];
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function botUser()
    {
        return $this->belongsTo(BotUser::class);
    }

    public function answers()
    {
        return $this->hasMany(RegistrationAnswer::class);
    }

    public static function generateUniqueTrackingCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('tracking_code', $code)->exists());

        return $code;
    }
}
