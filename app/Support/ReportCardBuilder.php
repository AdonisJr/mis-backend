<?php

namespace App\Support;

use App\Models\Assessment;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportCardBuilder
{
    /** @var Collection<int, object>|null */
    private ?Collection $snapshots = null;

    /** @var Collection<int, int>|null */
    private ?Collection $sectionRanks = null;

    public function __construct(private readonly Student $student, private readonly SchoolYear $schoolYear) {}

    public static function findSourceEnrollment(Student $student, int $schoolYearId): ?Enrollment
    {
        return Assessment::pickSourceEnrollment(
            $student->enrollments()->eligibleForAssessment($schoolYearId)->with('section.gradeLevel', 'section.program', 'section.adviser')->get(),
        );
    }

    /** @return array{source: Enrollment, subjects: list<array<string, mixed>>, general_averages: list<array<string, mixed>>} */
    public function build(): array
    {
        $source = self::findSourceEnrollment($this->student, $this->schoolYear->id);
        // the resource reads both without another query
        $source->setRelation('student', $this->student);
        $source->section->setRelation('schoolYear', $this->schoolYear);
        $subjectRows = $this->fetchEntries($source->section)->map(fn (CurriculumEntry $entry) => $this->buildSubjectRow($entry));

        return [
            'source' => $source,
            'subjects' => $subjectRows->all(),
            'general_averages' => $this->buildGeneralAverages($subjectRows),
        ];
    }

    /** @return Collection<int, CurriculumEntry> */
    private function fetchEntries(Section $section): Collection
    {
        return CurriculumEntry::query()
            ->orderedForList()
            ->where('curriculum_entries.school_year_id', $this->schoolYear->id)
            ->where('curriculum_entries.grade_level_id', $section->grade_level_id)
            ->where('curriculum_entries.program_id', $section->program_id)
            ->with('subject')
            ->get();
    }

    /** @return Collection<int, object> */
    private function fetchSnapshots(): Collection
    {
        // one join: the student's snapshots of this year, tagged with the entry and section they came from
        return $this->snapshots ??= DB::table('quarter_grades')
            ->join('class_quarters', 'class_quarters.id', '=', 'quarter_grades.class_quarter_id')
            ->join('classes', 'classes.id', '=', 'class_quarters.class_id')
            ->where('quarter_grades.student_id', $this->student->id)
            ->where('classes.school_year_id', $this->schoolYear->id)
            ->select('quarter_grades.quarterly_grade as grade', 'class_quarters.quarter', 'classes.curriculum_entry_id', 'classes.section_id')
            ->get();
    }

    /** @return Collection<int, int> */
    private function rankSections(): Collection
    {
        return $this->sectionRanks ??= Enrollment::query()->toBase()
            ->selectRaw('section_id, MAX(id) AS latest_enrollment_id')
            ->where('student_id', $this->student->id)
            ->whereIn('section_id', Section::query()->where('school_year_id', $this->schoolYear->id)->select('id'))
            ->groupBy('section_id')
            ->pluck('latest_enrollment_id', 'section_id');
    }

    /** @return array<string, mixed> */
    private function buildSubjectRow(CurriculumEntry $entry): array
    {
        $quarterRows = array_map(
            fn (int $quarter) => ['quarter' => $quarter, 'grade' => $this->pickQuarterGrade($entry, $quarter)],
            SchoolClass::listQuartersForTerm($entry->term_number),
        );
        $finalGrade = GradeCalculator::calculateFinalGrade(array_column($quarterRows, 'grade'));

        return [
            'curriculum_entry_id' => $entry->id,
            'term_number' => $entry->term_number,
            'subject' => ['id' => $entry->subject->id, 'code' => $entry->subject->code, 'name' => $entry->subject->name],
            'quarters' => $quarterRows,
            'final_grade' => $finalGrade,
            'remarks' => $finalGrade === null ? null : GradeCalculator::deriveRemarks($finalGrade),
        ];
    }

    private function pickQuarterGrade(CurriculumEntry $entry, int $quarter): ?int
    {
        $sectionRanks = $this->rankSections();
        // a moved student can be graded in two sections for one quarter: the section of the latest enrollment wins
        $snapshot = $this->fetchSnapshots()
            ->where('curriculum_entry_id', $entry->id)
            ->where('quarter', $quarter)
            ->sortByDesc(fn (object $row) => $sectionRanks->get($row->section_id, 0))
            ->first();

        return $snapshot ? (int) $snapshot->grade : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $subjectRows
     * @return list<array<string, mixed>>
     */
    private function buildGeneralAverages(Collection $subjectRows): array
    {
        return $subjectRows
            ->groupBy(fn (array $subjectRow) => $subjectRow['term_number'] ?? '')
            ->map(function (Collection $termRows) {
                $average = GradeCalculator::calculateGeneralAverage($termRows->pluck('final_grade')->all());

                return [
                    'term_number' => $termRows->first()['term_number'],
                    'average' => $average === null ? null : (string) $average,
                    'rounded_average' => $average === null ? null : GradeCalculator::roundAverage($average),
                    'descriptor' => $average === null ? null : GradeCalculator::describeAverage($average),
                ];
            })
            ->values()
            ->all();
    }
}
