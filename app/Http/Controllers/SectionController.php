<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListSectionsRequest;
use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Http\Resources\SectionResource;
use App\Models\Section;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SectionController extends Controller
{
    use SearchesPaginatedLists;

    public const ENROLLMENTS_DELETE_MESSAGE = 'The section has enrollments and cannot be deleted.';

    private const SEARCH_COLUMNS = ['sections.name'];

    private const RELATIONS = ['gradeLevel', 'program'];

    public function listSections(ListSectionsRequest $request): JsonResponse
    {
        $sectionQuery = $this->buildOrderedSectionQuery();
        $sectionQuery->withCount($this->buildEnrolledCount($request->validated('term_id')));
        $sectionQuery->where('sections.school_year_id', $request->validated('school_year_id'));
        $sectionQuery->when($request->filled('grade_level_id'), fn (Builder $query) => $query->where('sections.grade_level_id', $request->validated('grade_level_id')));
        $sectionQuery->when($request->filled('program_id'), fn (Builder $query) => $query->where('sections.program_id', $request->validated('program_id')));
        $this->applySearch($sectionQuery, (string) $request->validated('search'));
        $sectionPage = $sectionQuery->paginate();

        return response()->json([
            'sections' => SectionResource::collection($sectionPage->getCollection()),
            'meta' => $this->buildPageMeta($sectionPage),
        ]);
    }

    public function createSection(StoreSectionRequest $request): JsonResponse
    {
        $section = Section::create($request->validated())->load(self::RELATIONS)->loadCount($this->buildEnrolledCount(null));

        return response()->json([
            'section' => SectionResource::make($section),
        ], Response::HTTP_CREATED);
    }

    public function updateSection(UpdateSectionRequest $request, int $id): JsonResponse
    {
        $section = Section::findOrFail($id);
        $section->update($request->validated());
        $section->load(self::RELATIONS)->loadCount($this->buildEnrolledCount(null));

        return response()->json([
            'section' => SectionResource::make($section),
        ]);
    }

    public function deleteSection(int $id): Response
    {
        $section = Section::findOrFail($id);

        if ($section->enrollments()->exists()) {
            return response()->json(['message' => self::ENROLLMENTS_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $section->delete();

        return response()->noContent();
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function buildEnrolledCount(?int $termId): array
    {
        // a JHS section has no term, so its whole-year rows count for any term; without a term every enrolled row counts
        return ['enrollments as enrolled_count' => fn (Builder $enrollmentQuery) => $enrollmentQuery
            ->enrolled()
            ->when($termId, fn (Builder $countQuery) => $countQuery->where(fn (Builder $termQuery) => $termQuery->whereNull('enrollments.term_id')->orWhere('enrollments.term_id', $termId))),
        ];
    }

    private function buildOrderedSectionQuery(): Builder
    {
        // select only section columns: the joined tables also have id, name and timestamps
        return Section::query()->select('sections.*')->join('grade_levels', 'grade_levels.id', '=', 'sections.grade_level_id')->leftJoin('programs', 'programs.id', '=', 'sections.program_id')->with(self::RELATIONS)->orderBy('grade_levels.sort_order')->orderBy('programs.code')->orderBy('sections.name')->orderBy('sections.id');
    }
}
