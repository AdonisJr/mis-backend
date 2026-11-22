<?php

namespace App\Http\Requests;

use App\Models\GradeLevel;
use App\Models\Section;
use App\Models\User;
use Illuminate\Validation\Validator;

class ShowAdvisorySectionRequest extends ListTeacherClassesRequest
{
    private bool $isAdvisedSectionLoaded = false;

    private ?Section $advisedSection = null;

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->rejectMissingShsTerm($validator),
        ];
    }

    public function findAdvisedSection(): ?Section
    {
        if ($this->isAdvisedSectionLoaded) {
            return $this->advisedSection;
        }

        $this->isAdvisedSectionLoaded = true;
        $this->advisedSection = User::find($this->resolveTeacherIdInput())
            ?->advisedSections()
            ->with('gradeLevel')
            ->where('school_year_id', $this->input('school_year_id'))
            ->orderBy('id')
            ->first();

        return $this->advisedSection;
    }

    public function isShsSection(): bool
    {
        return $this->findAdvisedSection()?->gradeLevel->stage === GradeLevel::STAGE_SHS;
    }

    public function resolveTermNumber(): ?int
    {
        // a JHS section is whole-year, so the picked term is ignored (AMB-12)
        return $this->isShsSection() ? (int) $this->validated('term_number') : null;
    }

    // validated() is unavailable inside after(), so this reads the raw input
    private function resolveTeacherIdInput(): int
    {
        return (int) ($this->input('teacher_id') ?? $this->user()->id);
    }

    private function rejectMissingShsTerm(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['school_year_id', 'term_number', 'teacher_id']) || $this->filled('term_number') || ! $this->isShsSection()) {
            return;
        }

        $validator->errors()->add('term_number', StoreCurriculumEntryRequest::TERM_REQUIRED_MESSAGE);
    }
}
