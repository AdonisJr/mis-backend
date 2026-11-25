<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\ClassQuarter;
use App\Models\SchoolClass;
use App\Support\ClassRecordSheet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClassRecordSheet
 */
class ClassRecordResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    /** @return array{class_record: self} */
    public static function makeEnvelope(SchoolClass $schoolClass, int $quarter): array
    {
        return ['class_record' => self::make(new ClassRecordSheet($schoolClass, $quarter))];
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $schoolClass = $this->getSchoolClass();
        $classQuarter = $this->findClassQuarter();
        $studentRows = $this->buildStudentRows();

        return [
            'class' => TeacherClassResource::make($schoolClass->setAttribute('student_count', count($studentRows))),
            'quarter' => $this->getQuarter(),
            'quarters' => $schoolClass->listGradableQuarters(),
            'weights' => $this->resolveWeights(),
            'status' => $classQuarter?->status ?? ClassQuarter::STATUS_DRAFT,
            'submitted_by' => $this->buildUserSummary($classQuarter?->submittedBy),
            'submitted_at' => $classQuarter?->submitted_at,
            'activities' => GradingActivityResource::collection($this->fetchActivities()),
            'students' => $studentRows,
            'left_the_class' => $this->buildLeftRows(),
            'components_without_activities' => $this->listComponentsWithoutActivities(),
            'history' => ClassQuarterEventResource::collection($classQuarter?->events ?? []),
        ];
    }
}
