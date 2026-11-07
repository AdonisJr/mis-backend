<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
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
            'lrn' => $this->lrn,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'birth_date' => $this->birth_date->toDateString(),
            'gender' => $this->gender,
            'grade_level' => $this->grade_level,
            'section' => $this->section,
            'address' => $this->address,
            'contact' => $this->contact,
            'guardian_name' => $this->guardian_name,
            'guardian_relationship' => $this->guardian_relationship,
            'guardian_contact' => $this->guardian_contact,
            'rfid' => $this->rfid,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
