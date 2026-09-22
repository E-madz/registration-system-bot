<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationState extends Model
{
    public const STAGE_COLLECTING = 'collecting';

    public const STAGE_CONFIRMING = 'confirming';

    protected $fillable = [
        'bot_user_id',
        'program_id',
        'current_field_id',
        'stage',
        'answers',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
        ];
    }

    public function botUser()
    {
        return $this->belongsTo(BotUser::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function currentField()
    {
        return $this->belongsTo(ProgramField::class, 'current_field_id');
    }
}
