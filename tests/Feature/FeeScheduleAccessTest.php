<?php

namespace Tests\Feature;

use App\Models\FeeItem;
use App\Models\FeeSchedule;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithFees;
use Tests\TestCase;

class FeeScheduleAccessTest extends TestCase
{
    use InteractsWithFees;
    use RefreshDatabase;

    private FeeItem $feeItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->feeItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideFeeScheduleRoutes(): array
    {
        return [
            'list' => ['GET', self::FEE_SCHEDULES_URL.'?school_year_id={schoolYear}'],
            'show' => ['GET', self::FEE_SCHEDULES_URL.'/{feeSchedule}'],
            'create' => ['POST', self::FEE_SCHEDULES_URL],
            'update' => ['PUT', self::FEE_SCHEDULES_URL.'/{feeSchedule}'],
            'delete' => ['DELETE', self::FEE_SCHEDULES_URL.'/{feeSchedule}'],
            'lookup' => ['GET', self::FEE_SCHEDULES_URL.'/lookup?school_year_id={schoolYear}'],
            'copy' => ['POST', self::FEE_SCHEDULES_URL.'/copy'],
        ];
    }

    public function test_admin_can_use_every_fee_schedule_endpoint(): void
    {
        $keys = $this->buildKeys(self::GRADE_7);
        $targetYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);

        $createdScheduleId = $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL, [...$keys, 'lines' => $this->buildLines([[$this->feeItem, '100.00']])])
            ->assertCreated()
            ->json('fee_schedule.id');
        $scheduleUrl = self::FEE_SCHEDULES_URL.'/'.$createdScheduleId;
        $listUrl = self::FEE_SCHEDULES_URL.'?school_year_id='.$this->schoolYear->id;

        $this->withToken($this->adminToken)->getJson($listUrl)->assertOk()->assertJsonPath('fee_schedules.0.id', $createdScheduleId);
        $this->withToken($this->adminToken)->getJson($scheduleUrl)->assertOk()->assertJsonPath('fee_schedule.total', '100.00');
        $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL.'/lookup?'.http_build_query($keys))
            ->assertOk()
            ->assertJsonPath('fee_schedule.id', $createdScheduleId);
        $this->withToken($this->adminToken)
            ->putJson($scheduleUrl, ['lines' => $this->buildLines([[$this->feeItem, '150.00']])])
            ->assertOk()
            ->assertJsonPath('fee_schedule.total', '150.00');
        $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL.'/copy', ['source_school_year_id' => $this->schoolYear->id, 'target_school_year_id' => $targetYear->id])
            ->assertOk()
            ->assertJsonPath('copied_count', 1);
        $this->withToken($this->adminToken)->deleteJson($scheduleUrl)->assertNoContent();
    }

    #[DataProvider('provideFeeScheduleRoutes')]
    public function test_guest_gets_401_on_fee_schedule_routes(string $method, string $uriPattern): void
    {
        $targetSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7), [[$this->feeItem, '100.00']]);

        $this->json($method, $this->buildTargetUri($uriPattern, $targetSchedule), $this->buildChangedPayload())
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertTargetUnchanged($targetSchedule);
    }

    #[DataProvider('provideFeeScheduleRoutes')]
    public function test_registrar_gets_403_on_fee_schedule_routes(string $method, string $uriPattern): void
    {
        $targetSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7), [[$this->feeItem, '100.00']]);

        $this->withToken($this->createRegistrarToken())
            ->json($method, $this->buildTargetUri($uriPattern, $targetSchedule), $this->buildChangedPayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertTargetUnchanged($targetSchedule);
    }

    private function buildTargetUri(string $uriPattern, FeeSchedule $targetSchedule): string
    {
        return strtr($uriPattern, ['{schoolYear}' => $targetSchedule->school_year_id, '{feeSchedule}' => $targetSchedule->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChangedPayload(): array
    {
        $targetYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);

        return [
            ...$this->buildKeys(self::GRADE_8),
            'lines' => $this->buildLines([[$this->feeItem, '999.00']]),
            'source_school_year_id' => $this->schoolYear->id,
            'target_school_year_id' => $targetYear->id,
        ];
    }

    private function assertTargetUnchanged(FeeSchedule $targetSchedule): void
    {
        $this->assertDatabaseCount('fee_schedules', 1);
        $this->assertDatabaseCount('fee_schedule_lines', 1);
        $this->assertDatabaseHas('fee_schedule_lines', ['fee_schedule_id' => $targetSchedule->id, 'amount' => '100.00']);
        $this->assertSame($this->findGradeLevelId(self::GRADE_7), $targetSchedule->fresh()->grade_level_id);
    }
}
