<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentDiscount extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'assessment_id',
        'discount_type_name',
        'source',
        'value_type',
        'value',
        'base',
        'reference_no',
        'computed_amount',
        'applied_amount',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assessment_id' => 'integer',
            'value' => 'decimal:2',
            'computed_amount' => 'decimal:2',
            'applied_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}
