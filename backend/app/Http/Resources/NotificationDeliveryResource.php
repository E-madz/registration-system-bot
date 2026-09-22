<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bot_user_id' => $this->bot_user_id,
            'bot_user_name' => $this->whenLoaded('botUser', fn () => trim(($this->botUser->first_name ?? '').' '.($this->botUser->last_name ?? '')) ?: $this->botUser->username),
            'platform' => $this->platform,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'sent_at' => $this->sent_at,
        ];
    }
}
