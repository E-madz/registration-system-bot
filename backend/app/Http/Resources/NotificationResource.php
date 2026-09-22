<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'button_label' => $this->button_label,
            'button_program_id' => $this->button_program_id,
            'source' => $this->source,
            'audience_type' => $this->audience_type,
            'audience_program_id' => $this->audience_program_id,
            'audience_program_title' => $this->whenLoaded('audienceProgram', fn () => $this->audienceProgram?->title),
            'audience_bot_user_id' => $this->audience_bot_user_id,
            'audience_platform' => $this->audience_platform,
            'inactive_days' => $this->inactive_days,
            'status' => $this->status,
            'total_recipients' => $this->total_recipients,
            'sent_count' => $this->sent_count,
            'failed_count' => $this->failed_count,
            'skipped_banned_count' => $this->skipped_banned_count,
            'skipped_blocked_count' => $this->skipped_blocked_count,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'deliveries' => NotificationDeliveryResource::collection($this->whenLoaded('deliveries')),
        ];
    }
}
