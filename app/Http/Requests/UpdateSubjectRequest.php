<?php

namespace App\Http\Requests;

use Illuminate\Database\Eloquent\Builder;

class UpdateSubjectRequest extends StoreSubjectRequest
{
    protected function buildSameCodeQuery(): Builder
    {
        return parent::buildSameCodeQuery()->whereKeyNot($this->route('id'));
    }
}
