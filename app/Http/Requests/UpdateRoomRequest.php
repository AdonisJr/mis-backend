<?php

namespace App\Http\Requests;

use Illuminate\Database\Eloquent\Builder;

class UpdateRoomRequest extends StoreRoomRequest
{
    protected function buildSameNameQuery(): Builder
    {
        return parent::buildSameNameQuery()->whereKeyNot($this->route('id'));
    }
}
