<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTermRequest;
use App\Http\Requests\UpdateTermRequest;
use App\Http\Resources\ActiveTermResource;
use App\Http\Resources\TermResource;
use App\Models\SchoolYear;
use App\Models\Term;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TermController extends Controller
{
    public const ACTIVE_DELETE_MESSAGE = 'The active term cannot be deleted.';

    public const ENROLLMENTS_DELETE_MESSAGE = 'The term has enrollments and cannot be deleted.';

    public const INACTIVE_SCHOOL_YEAR_MESSAGE = 'Only a term of the active school year can be set active.';

    private const TRANSACTION_ATTEMPTS = 3;

    public function listTerms(int $id): JsonResponse
    {
        $schoolYear = SchoolYear::findOrFail($id);
        $terms = $schoolYear->terms()->orderBy('term_number')->get();

        return response()->json([
            'terms' => TermResource::collection($terms),
        ]);
    }

    public function createTerm(StoreTermRequest $request): JsonResponse
    {
        $term = $request->resolveSchoolYear()->terms()->create($request->validated());

        return response()->json([
            'term' => TermResource::make($term),
        ], Response::HTTP_CREATED);
    }

    public function updateTerm(UpdateTermRequest $request): JsonResponse
    {
        $term = $request->resolveTerm();
        $term->update($request->validated());

        return response()->json([
            'term' => TermResource::make($term),
        ]);
    }

    public function deleteTerm(int $id): Response
    {
        $term = Term::findOrFail($id);
        $hasBlockingEnrollments = ! $term->is_active && $term->enrollments()->exists();

        // the active-term message wins, so an active term with enrollments falls through to the conditional delete
        if ($hasBlockingEnrollments) {
            return response()->json(['message' => self::ENROLLMENTS_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $deletedCount = Term::whereKey($id)->where('is_active', false)->delete();

        // the conditional delete is the guard, so a concurrent set-active cannot slip in between check and delete
        if ($deletedCount === 0) {
            return response()->json(['message' => self::ACTIVE_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->noContent();
    }

    public function setActiveTerm(int $id): JsonResponse
    {
        $activeTerm = $this->activateTerm($id);

        if (! $activeTerm) {
            return response()->json(['message' => self::INACTIVE_SCHOOL_YEAR_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'term' => TermResource::make($activeTerm),
        ]);
    }

    public function fetchActiveTerm(): JsonResponse
    {
        $activeTerm = Term::with(['schoolYear' => fn (BelongsTo $schoolYearRelation) => $schoolYearRelation->withCount('terms')])
            ->where('is_active', true)
            ->first();

        if (! $activeTerm) {
            return response()->json(['term' => null]);
        }

        return response()->json([
            'term' => ActiveTermResource::make($activeTerm),
        ]);
    }

    private function activateTerm(int $id): ?Term
    {
        return DB::transaction(function () use ($id) {
            $term = Term::findOrFail($id);
            // locking the parent year row serializes this with a concurrent school-year set-active, which locks every school-year row before clearing terms
            $termSchoolYear = SchoolYear::lockForUpdate()->findOrFail($term->school_year_id);

            if (! $termSchoolYear->is_active) {
                return null;
            }

            // unconditional update locks every term row, so concurrent set-active calls serialize and cannot leave two active terms
            Term::query()->update(['is_active' => false]);
            Term::whereKey($id)->update(['is_active' => true]);

            return $term->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }
}
