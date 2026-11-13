<?php

namespace App\Http\Requests;

use Illuminate\Database\Eloquent\Builder;

class UpdateFeeItemRequest extends StoreFeeItemRequest
{
    protected function buildSameNameQuery(): Builder
    {
        return parent::buildSameNameQuery()->whereKeyNot($this->route('id'));
    }
}
