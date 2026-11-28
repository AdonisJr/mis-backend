<?php

namespace App\Models;

use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    public const GENDERS = ['male', 'female'];

    public const PARENT_RELATIONSHIPS = ['Mother', 'Father', 'Guardian', 'Other'];

    public const PARENT_LINK_TABLE = 'parent_student';

    public const ACCOUNT_STATUS_NONE = 'none';

    public const ACCOUNT_STATUS_ACTIVE = 'active';

    public const ACCOUNT_STATUS_INACTIVE = 'inactive';

    public const ACCOUNT_STATUS_MUST_CHANGE_PASSWORD = 'must_change_password';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lrn',
        'first_name',
        'middle_name',
        'last_name',
        'birth_date',
        'gender',
        'address',
        'contact',
        'guardian_name',
        'guardian_relationship',
        'guardian_contact',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function buildFullName(): string
    {
        return implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name], fn (?string $namePart) => $namePart !== null));
    }

    public function resolveAccountStatus(): string
    {
        if (! $this->user) {
            return self::ACCOUNT_STATUS_NONE;
        }

        return $this->user->resolveAccountStatus();
    }

    /** @return BelongsToMany<User, $this> */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, self::PARENT_LINK_TABLE, 'student_id', 'parent_user_id')
            ->withPivot('relationship')
            ->withTimestamps()
            ->orderBy('users.name')
            ->orderBy('users.id');
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasMany<Enrollment, $this> */
    public function currentEnrollments(): HasMany
    {
        return $this->enrollments()->current();
    }

    /** @return HasMany<StudentDiscount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(StudentDiscount::class);
    }

    /** @return HasMany<Assessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /** @return HasMany<PromissoryNote, $this> */
    public function promissoryNotes(): HasMany
    {
        return $this->hasMany(PromissoryNote::class)->orderByDesc('id');
    }

    /** @return HasMany<StudentAttendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(StudentAttendance::class);
    }
}
