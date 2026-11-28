<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'contact',
        'mobile_number',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /**
     * @param  list<string>  $roleNames
     */
    public function hasAnyRole(array $roleNames): bool
    {
        return $this->roles()->whereIn('name', $roleNames)->exists();
    }

    public function hasRole(string $roleName): bool
    {
        return $this->roles->contains('name', $roleName);
    }

    /** @param Builder<User> $query */
    public function scopeActiveTeachers(Builder $query): void
    {
        $query->where('is_active', true)->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', Role::TEACHER));
    }

    /** @param Builder<User> $query */
    public function scopeEmployees(Builder $query): void
    {
        $query->whereDoesntHave('roles', fn (Builder $roleQuery) => $roleQuery->whereIn('name', Role::MOBILE_ROLES));
    }

    /** @param Builder<User> $query */
    public function scopeParentAccounts(Builder $query): void
    {
        $query->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', Role::PARENT));
    }

    public function resolveAccountStatus(): string
    {
        if (! $this->is_active) {
            return Student::ACCOUNT_STATUS_INACTIVE;
        }
        if ($this->must_change_password) {
            return Student::ACCOUNT_STATUS_MUST_CHANGE_PASSWORD;
        }

        return Student::ACCOUNT_STATUS_ACTIVE;
    }

    /** @return HasOne<Student, $this> */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /** @return BelongsToMany<Student, $this> */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, Student::PARENT_LINK_TABLE, 'parent_user_id', 'student_id')
            ->withPivot('relationship')
            ->withTimestamps()
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->orderBy('students.id');
    }

    /** @return HasMany<SchoolClass, $this> */
    public function taughtClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'teacher_id');
    }

    /** @return HasMany<Section, $this> */
    public function advisedSections(): HasMany
    {
        return $this->hasMany(Section::class, 'adviser_id');
    }
}
