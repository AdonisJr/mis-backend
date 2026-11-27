<?php

namespace App\Models;

use Database\Factories\StudentAttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAttendance extends Model
{
    /** @use HasFactory<StudentAttendanceFactory> */
    use HasFactory;

    public const STATUS_PRESENT = 'present';

    public const STATUS_LATE = 'late';

    // derived for the roster and the mobile summary, never stored
    public const STATUS_ABSENT = 'absent';

    public const STATUSES = [self::STATUS_PRESENT, self::STATUS_LATE];

    public const TODAY_STATUS_FILTERS = [self::STATUS_PRESENT, self::STATUS_LATE, self::STATUS_ABSENT];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'student_id',
        'school_year_id',
        'date',
        'time_in',
        'time_out',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'school_year_id' => 'integer',
            'date' => 'date',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<SchoolYear, $this> */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }
}
