<?php

namespace App\Models;

use Database\Factories\SchoolYearFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolYear extends Model
{
    /** @use HasFactory<SchoolYearFactory> */
    use HasFactory;

    public const TERM_TYPE_SEMESTER = 'semester';

    public const TERM_TYPE_TRIMESTER = 'trimester';

    public const TERM_TYPES = [self::TERM_TYPE_SEMESTER, self::TERM_TYPE_TRIMESTER];

    public const TERM_COUNTS = [self::TERM_TYPE_SEMESTER => 2, self::TERM_TYPE_TRIMESTER => 3];

    public const TERM_TYPE_LABELS = [self::TERM_TYPE_SEMESTER => 'Semester', self::TERM_TYPE_TRIMESTER => 'Trimester'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'description',
        'term_type',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => false,
        'term_type' => self::TERM_TYPE_SEMESTER,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Term, $this> */
    public function terms(): HasMany
    {
        // chaperone hands each loaded term its parent, so term labels never lazy-load the school year per row
        return $this->hasMany(Term::class)->chaperone('schoolYear');
    }

    /** @return HasMany<Section, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    /** @return HasMany<FeeSchedule, $this> */
    public function feeSchedules(): HasMany
    {
        return $this->hasMany(FeeSchedule::class);
    }

    /** @return HasMany<PaymentPlan, $this> */
    public function paymentPlans(): HasMany
    {
        return $this->hasMany(PaymentPlan::class);
    }

    public function containsDate(string $date): bool
    {
        // Y-m-d strings compare correctly, and both bounds are inclusive
        return $date >= $this->start_date->toDateString() && $date <= $this->end_date->toDateString();
    }
}
