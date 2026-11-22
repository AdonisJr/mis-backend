<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListTimetableRequest;
use App\Http\Requests\ShowSectionTimetableRequest;
use App\Http\Resources\SectionTimetableRowResource;
use App\Http\Resources\TimetableMeetingResource;
use App\Models\ClassMeeting;
use App\Models\CurriculumEntry;
use App\Models\Room;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class TimetableController extends Controller
{
    public function showSectionTimetable(ShowSectionTimetableRequest $request, int $id): JsonResponse
    {
        $section = Section::with('gradeLevel')->findOrFail($id);
        $termNumber = $request->filled('term_number') ? (int) $request->validated('term_number') : null;

        $curriculumEntries = CurriculumEntry::query()
            ->orderedForList()
            ->where('curriculum_entries.school_year_id', $section->school_year_id)
            ->where('curriculum_entries.grade_level_id', $section->grade_level_id)
            ->where('curriculum_entries.program_id', $section->program_id)
            ->where('curriculum_entries.term_number', $termNumber)
            ->with([
                'subject',
                'schoolClasses' => fn ($classQuery) => $classQuery->where('section_id', $section->id)->with(SchoolClassController::RELATIONS),
            ])
            ->get();

        return response()->json([
            'section' => ['id' => $section->id, 'name' => $section->name, 'stage' => $section->gradeLevel->stage],
            'term_number' => $termNumber,
            'subjects' => SectionTimetableRowResource::collection($curriculumEntries),
        ]);
    }

    public function listTeacherTimetable(ListTimetableRequest $request, int $id): JsonResponse
    {
        User::findOrFail($id);
        $meetings = $this->buildTimetableQuery($request)->where('classes.teacher_id', $id)->get();

        return response()->json(['meetings' => TimetableMeetingResource::collection($meetings)]);
    }

    public function listRoomTimetable(ListTimetableRequest $request, int $id): JsonResponse
    {
        Room::findOrFail($id);
        $meetings = $this->buildTimetableQuery($request)->where('class_meetings.room_id', $id)->get();

        return response()->json(['meetings' => TimetableMeetingResource::collection($meetings)]);
    }

    /** @return Builder<ClassMeeting> */
    private function buildTimetableQuery(ListTimetableRequest $request): Builder
    {
        $termNumber = $request->validated('term_number');

        // select only meeting columns: classes.id and the timestamps would overwrite the meeting's
        return ClassMeeting::query()
            ->select('class_meetings.*')
            ->join('classes', 'classes.id', '=', 'class_meetings.class_id')
            ->where('classes.school_year_id', $request->validated('school_year_id'))
            ->when($request->filled('term_number'), fn (Builder $query) => $query->where(fn (Builder $termQuery) => $termQuery->whereNull('classes.term_number')->orWhere('classes.term_number', $termNumber)))
            ->with('room', 'schoolClass.section', 'schoolClass.curriculumEntry.subject', 'schoolClass.teacher')
            ->orderBy('class_meetings.day')
            ->orderBy('class_meetings.start_minutes')
            ->orderBy('class_meetings.id');
    }
}
