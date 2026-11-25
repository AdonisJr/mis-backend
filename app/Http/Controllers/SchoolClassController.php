<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSchoolClassRequest;
use App\Http\Requests\UpdateSchoolClassRequest;
use App\Http\Resources\SchoolClassResource;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use App\Support\ScheduleConflictFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class SchoolClassController extends Controller
{
    public const RELATIONS = SchoolClass::RESOURCE_RELATIONS;

    public const GRADING_DELETE_MESSAGE = 'The class has grading records and cannot be deleted.';

    public function showClass(int $id): JsonResponse
    {
        return response()->json([
            'class' => SchoolClassResource::make(SchoolClass::with(self::RELATIONS)->findOrFail($id)),
        ]);
    }

    public function createClass(StoreSchoolClassRequest $request): JsonResponse
    {
        $schoolClass = DB::transaction(fn () => $this->saveWithinLock($request, $request->buildNewClass()), SchoolClass::TRANSACTION_ATTEMPTS);

        return response()->json([
            'class' => SchoolClassResource::make($schoolClass),
        ], Response::HTTP_CREATED);
    }

    public function updateClass(UpdateSchoolClassRequest $request, int $id): JsonResponse
    {
        $schoolClass = SchoolClass::findOrFail($id);
        $schoolClass = DB::transaction(fn () => $this->saveWithinLock($request, $schoolClass), SchoolClass::TRANSACTION_ATTEMPTS);

        return response()->json([
            'class' => SchoolClassResource::make($schoolClass),
        ]);
    }

    public function deleteClass(int $id): Response|JsonResponse
    {
        $schoolClass = SchoolClass::findOrFail($id);
        $isDeleted = DB::transaction(fn () => $this->deleteUnlessGraded($schoolClass), SchoolClass::TRANSACTION_ATTEMPTS);

        if (! $isDeleted) {
            return response()->json(['message' => self::GRADING_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->noContent();
    }

    private function deleteUnlessGraded(SchoolClass $schoolClass): bool
    {
        // the same row lock as every grading write, so a racing first activity cannot slip past the check
        SchoolClass::whereKey($schoolClass->id)->lockForUpdate()->first();

        if ($schoolClass->gradingActivities()->exists() || $schoolClass->classQuarters()->exists()) {
            return false;
        }

        return $schoolClass->delete();
    }

    private function saveWithinLock(StoreSchoolClassRequest $request, SchoolClass $schoolClass): SchoolClass
    {
        // every class save of a school year serializes on this row, so two saves cannot both pass the duplicate and conflict checks
        SchoolYear::whereKey($schoolClass->school_year_id)->lockForUpdate()->first();

        if ($request->findDuplicateClass()) {
            throw ValidationException::withMessages(['curriculum_entry_id' => StoreSchoolClassRequest::DUPLICATE_CLASS_MESSAGE]);
        }

        $schoolClass->teacher_id = (int) $request->validated('teacher_id');
        $meetingRows = $request->buildMeetingRows();
        $this->rejectConflicts($schoolClass, $meetingRows);

        $schoolClass->save();
        $schoolClass->meetings()->delete();
        $schoolClass->meetings()->createMany($meetingRows);
        $schoolClass->touch();

        return $schoolClass->load(self::RELATIONS);
    }

    /** @param list<array{day: int, start_minutes: int, end_minutes: int, room_id: int|null}> $meetingRows */
    private function rejectConflicts(SchoolClass $schoolClass, array $meetingRows): void
    {
        $conflictFinder = new ScheduleConflictFinder($schoolClass);
        $conflictMessages = [];

        foreach ($meetingRows as $meetingIndex => $meetingRow) {
            $clashingMeeting = $conflictFinder->findConflict($meetingRow);

            if ($clashingMeeting) {
                $conflictMessages["meetings.{$meetingIndex}"] = $conflictFinder->formatConflictMessage($clashingMeeting, $meetingRow['room_id']);
            }
        }

        if ($conflictMessages) {
            throw ValidationException::withMessages($conflictMessages);
        }
    }
}
