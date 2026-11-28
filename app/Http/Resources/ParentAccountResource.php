<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParentAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mobile_number' => $this->mobile_number,
            'email' => $this->email,
            'status' => $this->resolveAccountStatus(),
            'must_change_password' => (bool) $this->must_change_password,
            'is_active' => (bool) $this->is_active,
            'children' => $this->children->map(fn (Student $child) => [
                ...$child->only(['id', 'lrn', 'first_name', 'middle_name', 'last_name']),
                'relationship' => $child->pivot->relationship,
            ])->all(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
