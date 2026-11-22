<?php

namespace App\Models;

use Database\Factories\GradingScoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradingScore extends Model
{
    /** @use HasFactory<GradingScoreFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['grading_activity_id', 'student_id', 'score'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grading_activity_id' => 'integer',
            'student_id' => 'integer',
            'score' => 'decimal:1',
        ];
    }

    /** @return BelongsTo<GradingActivity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(GradingActivity::class, 'grading_activity_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
