<?php

namespace App\Http\Resources;

use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Enrollment
 */
class RosterStudentResource extends JsonResource
{
    private bool $canSeeGuardians = false;

    public function includeGuardians(bool $canSeeGuardians): static
    {
        $this->canSeeGuardians = $canSeeGuardians;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'enrollment_id' => $this->id,
            'student_id' => $this->student->id,
            'last_name' => $this->student->last_name,
            'first_name' => $this->student->first_name,
            'middle_name' => $this->student->middle_name,
            'lrn' => $this->student->lrn,
            'gender' => $this->student->gender,
            'status' => $this->status,
            // mergeWhen leaves the keys out entirely; a null value would still tell the client the field exists
            $this->mergeWhen($this->canSeeGuardians, fn () => [
                'guardian_name' => $this->student->guardian_name,
                'guardian_relationship' => $this->student->guardian_relationship,
                'guardian_contact' => $this->student->guardian_contact,
            ]),
        ];
    }
}
