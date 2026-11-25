<?php

namespace App\Support;

use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ClassListAccess
{
    private readonly bool $isRegistry;

    public function __construct(private readonly User $user)
    {
        $this->isRegistry = $user->hasAnyRole(Role::REGISTRY_ROLES);
    }

    public function isRegistryCaller(): bool
    {
        return $this->isRegistry;
    }

    public function canReadClass(SchoolClass $schoolClass): bool
    {
        return $this->isRegistry
            || $schoolClass->teacher_id === $this->user->id
            || $schoolClass->section->adviser_id === $this->user->id;
    }

    public function canWriteClass(SchoolClass $schoolClass): bool
    {
        return $schoolClass->teacher_id === $this->user->id;
    }

    public function canReadReportCard(Student $student, int $schoolYearId): bool
    {
        if ($this->isRegistry) {
            return true;
        }

        // every enrollment status counts: a student who left the section still has a card there
        return Section::query()
            ->where('school_year_id', $schoolYearId)
            ->where('adviser_id', $this->user->id)
            ->whereHas('enrollments', fn (Builder $enrollmentQuery) => $enrollmentQuery->where('student_id', $student->id))
            ->exists();
    }

    public function canReadSectionReportCards(Section $section): bool
    {
        // same rule as guardian visibility: registry or the section's current adviser
        return $this->canSeeGuardians($section);
    }

    public function canSeeGuardians(Section $section): bool
    {
        return $this->isRegistry || $section->adviser_id === $this->user->id;
    }
}
