<?php

namespace App\Models;

use Database\Factories\QuarterGradeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuarterGrade extends Model
{
    /** @use HasFactory<QuarterGradeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'student_id',
        'ww_percentage',
        'ww_weighted',
        'pt_percentage',
        'pt_weighted',
        'qa_percentage',
        'qa_weighted',
        'initial_grade',
        'quarterly_grade',
        'ww_weight',
        'pt_weight',
        'qa_weight',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_quarter_id' => 'integer',
            'student_id' => 'integer',
            'ww_percentage' => 'decimal:2',
            'ww_weighted' => 'decimal:2',
            'pt_percentage' => 'decimal:2',
            'pt_weighted' => 'decimal:2',
            'qa_percentage' => 'decimal:2',
            'qa_weighted' => 'decimal:2',
            'initial_grade' => 'decimal:2',
            'quarterly_grade' => 'integer',
            'ww_weight' => 'integer',
            'pt_weight' => 'integer',
            'qa_weight' => 'integer',
        ];
    }

    /** @return BelongsTo<ClassQuarter, $this> */
    public function classQuarter(): BelongsTo
    {
        return $this->belongsTo(ClassQuarter::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
