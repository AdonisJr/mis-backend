<?php

namespace App\Models;

use Database\Factories\SectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'school_year_id',
        'grade_level_id',
        'program_id',
        'name',
        'capacity',
        'room',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // form input may send ids and capacity as digit strings; the casts keep the JSON numeric
        return [
            'school_year_id' => 'integer',
            'grade_level_id' => 'integer',
            'program_id' => 'integer',
            'capacity' => 'integer',
        ];
    }

    /** @return BelongsTo<SchoolYear, $this> */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /** @return BelongsTo<GradeLevel, $this> */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function countMostSeatsTaken(): int
    {
        $seatCounts = $this->enrollments()->enrolled()->toBase()->selectRaw('COUNT(*) AS seats_taken')->groupBy('term_id')->pluck('seats_taken');

        return (int) ($seatCounts->max() ?? 0);
    }
}
