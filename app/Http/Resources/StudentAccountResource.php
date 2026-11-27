<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resolveAccountStatus(),
            'must_change_password' => (bool) $this->user?->must_change_password,
            'is_active' => (bool) $this->user?->is_active,
        ];
    }
}
