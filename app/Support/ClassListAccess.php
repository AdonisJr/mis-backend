<?php

namespace App\Support;

use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\User;

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

    public function canSeeGuardians(Section $section): bool
    {
        return $this->isRegistry || $section->adviser_id === $this->user->id;
    }
}
