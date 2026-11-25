<?php

namespace App\Models;

use Database\Factories\ClassQuarterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassQuarter extends Model
{
    /** @use HasFactory<ClassQuarterFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_SUBMITTED];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['class_id', 'quarter'];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => self::STATUS_DRAFT];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_id' => 'integer',
            'quarter' => 'integer',
            'submitted_by' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return HasMany<ClassQuarterEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ClassQuarterEvent::class)->orderBy('id');
    }

    /** @return HasMany<QuarterGrade, $this> */
    public function grades(): HasMany
    {
        return $this->hasMany(QuarterGrade::class);
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }
}
