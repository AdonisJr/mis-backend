<?php

namespace App\Http\Requests;

use App\Models\GradingActivity;
use App\Support\SectionRoster;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

class SaveGradingScoresRequest extends ChangeClassQuarterRequest
{
    public const NOT_ON_ROSTER_MESSAGE = 'The student is not on this class roster.';

    public const ACTIVITY_OUTSIDE_MESSAGE = 'The activity is not in this class and quarter.';

    public const SCORE_ABOVE_HIGHEST_MESSAGE = 'The score may not be greater than %d.';

    public const DUPLICATE_ROW_MESSAGE = 'This student and activity appear more than once.';

    public const SCORE_ROWS_MAX = 5000;

    private const ONE_DECIMAL_RULE = 'regex:/\A[0-9]+(\.[0-9])?\z/';

    /** @var array<string, bool> */
    private array $seenPairs = [];

    /** @var Collection<int, GradingActivity>|null */
    private ?Collection $quarterActivities = null;

    /** @var list<int>|null */
    private ?array $rosterStudentIds = null;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $idRules = ['bail', 'required', 'integer', StoreSectionRequest::WHOLE_NUMBER_RULE];

        return [
            'scores' => ['required', 'array', 'min:1', 'max:'.self::SCORE_ROWS_MAX],
            'scores.*.student_id' => $idRules,
            'scores.*.activity_id' => $idRules,
            'scores.*.score' => ['bail', 'nullable', 'numeric', 'min:0', self::ONE_DECIMAL_RULE],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->rejectInvalidRows($validator),
        ];
    }

    /** @return list<array{grading_activity_id: int, student_id: int, score: string|null, created_at: Carbon, updated_at: Carbon}> */
    public function buildUpsertRows(): array
    {
        $savedAt = now();

        return array_map(fn (array $row) => [
            'grading_activity_id' => (int) $row['activity_id'],
            'student_id' => (int) $row['student_id'],
            'score' => ($row['score'] ?? null) === null ? null : (string) $row['score'],
            'created_at' => $savedAt,
            'updated_at' => $savedAt,
        ], array_values($this->validated('scores')));
    }

    private function rejectInvalidRows(Validator $validator): void
    {
        $scoreRows = $this->input('scores');

        if ($validator->errors()->has('scores') || ! is_array($scoreRows)) {
            return;
        }

        foreach (array_keys($scoreRows) as $rowKey) {
            if ($validator->errors()->hasAny(["scores.{$rowKey}.student_id", "scores.{$rowKey}.activity_id", "scores.{$rowKey}.score"])) {
                continue;
            }

            foreach ($this->buildRowErrors($rowKey) as $errorKey => $message) {
                $validator->errors()->add($errorKey, $message);
            }
        }
    }

    /** @return array<string, string> */
    private function buildRowErrors(int|string $rowKey): array
    {
        $row = $this->input("scores.{$rowKey}");
        $studentId = (int) $row['student_id'];
        $pairKey = $studentId.'-'.(int) $row['activity_id'];

        if (isset($this->seenPairs[$pairKey])) {
            return ["scores.{$rowKey}" => self::DUPLICATE_ROW_MESSAGE];
        }

        $this->seenPairs[$pairKey] = true;
        $activity = $this->listQuarterActivities()->get((int) $row['activity_id']);

        if (! $activity) {
            return ["scores.{$rowKey}.activity_id" => self::ACTIVITY_OUTSIDE_MESSAGE];
        }

        if (! in_array($studentId, $this->listRosterStudentIds(), true)) {
            return ["scores.{$rowKey}.student_id" => self::NOT_ON_ROSTER_MESSAGE];
        }

        $score = $row['score'] ?? null;

        if ($score !== null && BigDecimal::of((string) $score)->isGreaterThan($activity->highest_possible_score)) {
            return ["scores.{$rowKey}.score" => sprintf(self::SCORE_ABOVE_HIGHEST_MESSAGE, $activity->highest_possible_score)];
        }

        return [];
    }

    /** @return Collection<int, GradingActivity> */
    private function listQuarterActivities(): Collection
    {
        return $this->quarterActivities ??= $this->findSchoolClass()->gradingActivities()->where('quarter', $this->resolveQuarter())->get()->keyBy('id');
    }

    /** @return list<int> */
    private function listRosterStudentIds(): array
    {
        $schoolClass = $this->findSchoolClass();

        return $this->rosterStudentIds ??= (new SectionRoster($schoolClass->section, $schoolClass->term_number))
            ->fetchGroups()['students']->pluck('student_id')->all();
    }
}
