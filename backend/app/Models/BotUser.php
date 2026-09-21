<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BotUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'external_id',
        'chat_id',
        'first_name',
        'last_name',
        'username',
        'phone',
        'last_interaction_at',
        'is_blocked',
        'blocked_at',
        'is_banned',
        'banned_at',
        'ban_reason',
    ];

    protected function casts(): array
    {
        return [
            'last_interaction_at' => 'datetime',
            'is_blocked' => 'boolean',
            'blocked_at' => 'datetime',
            'is_banned' => 'boolean',
            'banned_at' => 'datetime',
        ];
    }

    public function registrations()
    {
        return $this->hasMany(Registration::class);
    }

    public function conversationState()
    {
        return $this->hasOne(ConversationState::class);
    }
}
