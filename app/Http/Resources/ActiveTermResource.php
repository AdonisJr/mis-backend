<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ActiveTermResource extends TermResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'school_year' => SchoolYearResource::make($this->schoolYear)];
    }
}
