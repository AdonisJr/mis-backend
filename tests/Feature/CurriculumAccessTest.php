<?php

namespace Tests\Feature;

use App\Models\CurriculumEntry;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithCurriculum;
use Tests\TestCase;

class CurriculumAccessTest extends TestCase
{
    use InteractsWithCurriculum;
    use RefreshDatabase;

    private CurriculumEntry $targetEntry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->targetEntry = $this->createEntry([...$this->buildJhsEntryPayload($this->createSubject(self::MATH_7)), 'sort_order' => 3]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideWriteRoutes(): array
    {
        return [
            'create' => ['POST', self::CURRICULUM_URL],
            'copy' => ['POST', self::CURRICULUM_COPY_URL],
            'update' => ['PUT', self::CURRICULUM_URL.'/{entry}'],
            'delete' => ['DELETE', self::CURRICULUM_URL.'/{entry}'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEveryRoute(): array
    {
        return ['list' => ['GET', self::CURRICULUM_URL], ...self::provideWriteRoutes()];
    }

    public function test_admin_can_use_every_curriculum_endpoint(): void
    {
        $this->assertEveryEndpointWorksFor($this->adminToken);
    }

    public function test_registrar_can_use_every_curriculum_endpoint(): void
    {
        $this->assertEveryEndpointWorksFor($this->createRegistrarToken());
    }

    public function test_cashier_can_read_the_curriculum(): void
    {
        $this->withToken($this->createCashierToken())
            ->getJson(self::CURRICULUM_URL.'?school_year_id='.$this->schoolYear->id)
            ->assertOk()
            ->assertJsonPath('curriculum_entries.0.id', $this->targetEntry->id);
    }

    #[DataProvider('provideWriteRoutes')]
    public function test_cashier_gets_403_on_curriculum_writes(string $method, string $uriPattern): void
    {
        $this->withToken($this->createCashierToken())
            ->json($method, strtr($uriPattern, ['{entry}' => $this->targetEntry->id]), $this->buildChangedPayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertTargetUnchanged();
    }

    #[DataProvider('provideEveryRoute')]
    public function test_role_less_user_gets_403_on_curriculum_routes(string $method, string $uriPattern): void
    {
        $this->withToken($this->createRoleLessToken())
            ->json($method, strtr($uriPattern, ['{entry}' => $this->targetEntry->id]), $this->buildChangedPayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertTargetUnchanged();
    }

    #[DataProvider('provideEveryRoute')]
    public function test_guest_gets_401_on_curriculum_routes(string $method, string $uriPattern): void
    {
        $this->json($method, strtr($uriPattern, ['{entry}' => $this->targetEntry->id]), $this->buildChangedPayload())
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertTargetUnchanged();
    }

    private function assertEveryEndpointWorksFor(string $token): void
    {
        $targetYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $newSubject = $this->createSubject(self::ENGLISH_7);

        $createdEntryId = $this->withToken($token)
            ->postJson(self::CURRICULUM_URL, $this->buildJhsEntryPayload($newSubject))
            ->assertCreated()
            ->json('curriculum_entry.id');
        $this->withToken($token)->getJson(self::CURRICULUM_URL.'?school_year_id='.$this->schoolYear->id)->assertOk()->assertJsonCount(2, 'curriculum_entries');
        $this->withToken($token)->putJson(self::CURRICULUM_URL.'/'.$createdEntryId, ['sort_order' => 9])->assertOk()->assertJsonPath('curriculum_entry.sort_order', 9);
        $this->withToken($token)
            ->postJson(self::CURRICULUM_COPY_URL, ['source_school_year_id' => $this->schoolYear->id, 'target_school_year_id' => $targetYear->id])
            ->assertOk()
            ->assertJsonPath('copied_count', 2);
        $this->withToken($token)->deleteJson(self::CURRICULUM_URL.'/'.$createdEntryId)->assertNoContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChangedPayload(): array
    {
        return ['sort_order' => 9, 'source_school_year_id' => $this->schoolYear->id, 'target_school_year_id' => $this->schoolYear->id] + $this->buildJhsEntryPayload($this->createSubject(self::ENGLISH_7));
    }

    private function assertTargetUnchanged(): void
    {
        $this->assertSame(3, $this->targetEntry->fresh()->sort_order);
        $this->assertSame(1, CurriculumEntry::count());
    }
}
