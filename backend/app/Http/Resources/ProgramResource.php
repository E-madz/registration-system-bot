<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status,
            'registration_starts_at' => $this->registration_starts_at,
            'registration_ends_at' => $this->registration_ends_at,
            'capacity' => $this->capacity,
            'requires_approval' => $this->requires_approval,
            'success_message' => $this->success_message,
            'media_url' => $this->media_url,
            'has_registrations' => $this->hasAnyRegistration(),
            'confirmed_registrations_count' => $this->confirmedRegistrationsCount(),
            'fields' => ProgramFieldResource::collection($this->whenLoaded('fields')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
