<?php

namespace App\Http\Requests;

use App\Models\SchoolYear;
use App\Models\Term;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rules\Unique;

class UpdateTermRequest extends StoreTermRequest
{
    private ?Term $term = null;

    public function resolveTerm(): Term
    {
        return $this->term ??= Term::findOrFail($this->route('id'));
    }

    public function resolveSchoolYear(): SchoolYear
    {
        return $this->resolveTerm()->schoolYear;
    }

    protected function getUniqueTermNumberRule(): Unique
    {
        return parent::getUniqueTermNumberRule()->ignore($this->resolveTerm()->id);
    }

    /** @return Collection<int, Term> */
    protected function getOtherTerms(): Collection
    {
        return parent::getOtherTerms()->except([$this->resolveTerm()->id]);
    }
}
