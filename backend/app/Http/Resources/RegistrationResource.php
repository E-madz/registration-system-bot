<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tracking_code' => $this->tracking_code,
            'status' => $this->status,
            'canceled_at' => $this->canceled_at,
            'canceled_reason' => $this->canceled_reason,
            'created_at' => $this->created_at,
            'bot_user' => new BotUserResource($this->whenLoaded('botUser')),
            'answers' => RegistrationAnswerResource::collection($this->whenLoaded('answers')),
            'program' => $this->whenLoaded('program', fn () => [
                'id' => $this->program->id,
                'title' => $this->program->title,
            ]),
        ];
    }
}
