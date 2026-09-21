<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BotUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'external_id' => $this->external_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'username' => $this->username,
            'phone' => $this->phone,
            'is_banned' => $this->is_banned,
            'banned_at' => $this->banned_at,
            'ban_reason' => $this->ban_reason,
            'is_blocked' => $this->is_blocked,
            'blocked_at' => $this->blocked_at,
            'last_interaction_at' => $this->last_interaction_at,
        ];
    }
}
