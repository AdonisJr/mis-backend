<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeClassQuarterRequest;
use App\Http\Requests\ReopenClassQuarterRequest;
use App\Http\Resources\ClassRecordResource;
use App\Models\ClassQuarter;
use App\Models\ClassQuarterEvent;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Support\ClassRecordSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ClassQuarterController extends Controller
{
    public function submitQuarter(ChangeClassQuarterRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->freezeQuarter($request), SchoolClass::TRANSACTION_ATTEMPTS);

        return response()->json(ClassRecordResource::makeEnvelope($request->findSchoolClass(), $request->resolveQuarter()));
    }

    public function reopenQuarter(ReopenClassQuarterRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->unfreezeQuarter($request), SchoolClass::TRANSACTION_ATTEMPTS);

        return response()->json(ClassRecordResource::makeEnvelope($request->findSchoolClass(), $request->resolveQuarter()));
    }

    private function freezeQuarter(ChangeClassQuarterRequest $request): void
    {
        $schoolClass = $request->findSchoolClass();
        $quarter = $request->resolveQuarter();
        $schoolClass->lockOpenQuarter($quarter);

        $classQuarter = ClassQuarter::firstOrCreate(['class_id' => $schoolClass->id, 'quarter' => $quarter]);
        $classQuarter->forceFill([
            'status' => ClassQuarter::STATUS_SUBMITTED,
            'submitted_by' => $request->user()->id,
            'submitted_at' => now(),
        ])->save();
        $classQuarter->events()->save($this->buildEvent($request, ClassQuarterEvent::ACTION_SUBMITTED));
        $this->writeSnapshots($classQuarter, new ClassRecordSheet($schoolClass, $quarter));
    }

    private function unfreezeQuarter(ReopenClassQuarterRequest $request): void
    {
        $classQuarter = $request->findSchoolClass()->lockSubmittedQuarter($request->resolveQuarter());

        $classQuarter->grades()->delete();
        $classQuarter->forceFill([
            'status' => ClassQuarter::STATUS_DRAFT,
            'submitted_by' => null,
            'submitted_at' => null,
        ])->save();
        $classQuarter->events()->save($this->buildEvent($request, ClassQuarterEvent::ACTION_REOPENED));
    }

    private function buildEvent(ChangeClassQuarterRequest|ReopenClassQuarterRequest $request, string $action): ClassQuarterEvent
    {
        return (new ClassQuarterEvent(['action' => $action, 'reason' => $request->validated('reason')]))
            ->forceFill(['user_id' => $request->user()->id]);
    }

    private function writeSnapshots(ClassQuarter $classQuarter, ClassRecordSheet $sheet): void
    {
        // left students are not graded in the snapshot; the record shows them live
        foreach ($sheet->fetchRosterGroups()['students'] as $enrollment) {
            $classQuarter->grades()->create($this->buildSnapshotFields($enrollment, $sheet));
        }
    }

    /** @return array<string, mixed> */
    private function buildSnapshotFields(Enrollment $enrollment, ClassRecordSheet $sheet): array
    {
        $grades = $sheet->computeGrades($enrollment->student_id);
        $weights = $sheet->resolveWeights();

        return [
            'student_id' => $enrollment->student_id,
            'ww_percentage' => $grades['ww']['percentage'],
            'ww_weighted' => $grades['ww']['weighted'],
            'pt_percentage' => $grades['pt']['percentage'],
            'pt_weighted' => $grades['pt']['weighted'],
            'qa_percentage' => $grades['qa']['percentage'],
            'qa_weighted' => $grades['qa']['weighted'],
            'initial_grade' => $grades['initial_grade'],
            'quarterly_grade' => $grades['quarterly_grade'],
            'ww_weight' => $weights['ww'],
            'pt_weight' => $weights['pt'],
            'qa_weight' => $weights['qa'],
        ];
    }
}
