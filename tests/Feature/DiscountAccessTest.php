<?php

namespace Tests\Feature;

use App\Models\DiscountType;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentDiscount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithDiscounts;
use Tests\TestCase;

class DiscountAccessTest extends TestCase
{
    use InteractsWithDiscounts;
    use RefreshDatabase;

    private DiscountType $discountType;

    private StudentDiscount $studentDiscount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->discountType = $this->createType(['name' => self::SIBLING_TYPE]);
        $this->studentDiscount = StudentDiscount::factory()->create(['school_year_id' => $this->schoolYear->id]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideDiscountRoutes(): array
    {
        return [
            'type list' => ['GET', self::DISCOUNT_TYPES_URL],
            'type show' => ['GET', self::DISCOUNT_TYPES_URL.'/{type}'],
            'type create' => ['POST', self::DISCOUNT_TYPES_URL],
            'type update' => ['PUT', self::DISCOUNT_TYPES_URL.'/{type}'],
            'type deactivate' => ['PUT', self::DISCOUNT_TYPES_URL.'/{type}/deactivate'],
            'type reactivate' => ['PUT', self::DISCOUNT_TYPES_URL.'/{type}/reactivate'],
            'type delete' => ['DELETE', self::DISCOUNT_TYPES_URL.'/{type}'],
            'assignment list' => ['GET', self::STUDENT_DISCOUNTS_URL.'?school_year_id={schoolYear}'],
            'assignment create' => ['POST', self::STUDENT_DISCOUNTS_URL],
            'assignment update' => ['PUT', self::STUDENT_DISCOUNTS_URL.'/{assignment}'],
            'assignment delete' => ['DELETE', self::STUDENT_DISCOUNTS_URL.'/{assignment}'],
        ];
    }

    public function test_admin_can_use_every_discount_type_endpoint(): void
    {
        $typeUrl = self::DISCOUNT_TYPES_URL.'/'.$this->discountType->id;

        $createdTypeId = $this->postType($this->buildTypePayload(['name' => self::VOUCHER_TYPE]))->assertCreated()->json('discount_type.id');
        $this->withToken($this->adminToken)->getJson(self::DISCOUNT_TYPES_URL)->assertOk()->assertJsonPath('meta.total', 3);
        $this->withToken($this->adminToken)->getJson($typeUrl)->assertOk()->assertJsonPath('discount_type.id', $this->discountType->id);
        $this->putType($this->discountType->id, $this->buildTypePayload(['name' => 'Renamed']))->assertOk()->assertJsonPath('discount_type.name', 'Renamed');
        $this->withToken($this->adminToken)->putJson($typeUrl.'/deactivate')->assertOk()->assertJsonPath('discount_type.is_active', false);
        $this->withToken($this->adminToken)->putJson($typeUrl.'/reactivate')->assertOk()->assertJsonPath('discount_type.is_active', true);
        $this->withToken($this->adminToken)->deleteJson(self::DISCOUNT_TYPES_URL.'/'.$createdTypeId)->assertNoContent();
        $this->withToken($this->adminToken)->deleteJson($typeUrl)->assertNoContent();
    }

    public function test_admin_can_use_every_student_discount_endpoint(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $assignmentUrl = self::STUDENT_DISCOUNTS_URL.'/'.$this->studentDiscount->id;

        $createdId = $this->postAssignment($this->buildAssignmentPayload($student, $this->discountType))->assertCreated()->json('student_discount.id');
        $this->withToken($this->adminToken)->getJson(self::STUDENT_DISCOUNTS_URL.'?school_year_id='.$this->schoolYear->id)->assertOk()->assertJsonPath('meta.total', 2);
        $this->withToken($this->adminToken)->putJson($assignmentUrl, ['reference_no' => 'REF'])->assertOk()->assertJsonPath('student_discount.reference_no', 'REF');
        $this->withToken($this->adminToken)->deleteJson(self::STUDENT_DISCOUNTS_URL.'/'.$createdId)->assertNoContent();
        $this->withToken($this->adminToken)->deleteJson($assignmentUrl)->assertNoContent();
    }

    #[DataProvider('provideDiscountRoutes')]
    public function test_guest_gets_401_on_discount_routes(string $method, string $uriPattern): void
    {
        $snapshot = $this->snapshotDiscountTables();

        $this->json($method, $this->buildTargetUri($uriPattern), $this->buildChangedPayload())
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertEquals($snapshot, $this->snapshotDiscountTables());
    }

    #[DataProvider('provideDiscountRoutes')]
    public function test_registrar_gets_403_on_discount_routes(string $method, string $uriPattern): void
    {
        $snapshot = $this->snapshotDiscountTables();

        $this->withToken($this->createRegistrarToken())
            ->json($method, $this->buildTargetUri($uriPattern), $this->buildChangedPayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertEquals($snapshot, $this->snapshotDiscountTables());
        $this->assertTrue($this->discountType->fresh()->is_active);
    }

    private function buildTargetUri(string $uriPattern): string
    {
        return str_replace(
            ['{schoolYear}', '{type}', '{assignment}'],
            [$this->schoolYear->id, $this->discountType->id, $this->studentDiscount->id],
            $uriPattern,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChangedPayload(): array
    {
        return [
            ...$this->buildTypePayload(['name' => 'Changed']),
            ...$this->buildAssignmentPayload(Student::factory()->create(), $this->discountType),
            'reference_no' => 'CHANGED',
        ];
    }
}
