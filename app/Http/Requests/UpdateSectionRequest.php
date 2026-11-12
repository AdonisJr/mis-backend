<?php

namespace App\Http\Requests;

use App\Models\Program;
use App\Models\Section;
use Illuminate\Database\Eloquent\Builder;

class UpdateSectionRequest extends StoreSectionRequest
{
    protected function isProgramChanged(Program $program): bool
    {
        // a section may keep the inactive program it already has; a missing id counts as changed and the controller answers 404
        return Section::find($this->route('id'))?->program_id !== $program->id;
    }

    protected function buildSameGroupQuery(): Builder
    {
        return parent::buildSameGroupQuery()->whereKeyNot($this->route('id'));
    }
}
