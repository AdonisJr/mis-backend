<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class TeacherListTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    private const PAGE_SIZE = 15;

    private const TEACHER_ROUTES = [self::TEACHERS_URL, self::ACTIVE_TEACHERS_URL];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidSearches(): array
    {
        return [
            'array' => ['search[]=maria'],
            'too long' => ['search='.str_repeat('a', 256)],
        ];
    }

    public function test_list_returns_only_active_teachers_with_exact_keys(): void
    {
        $teacher = $this->createTeacher(['name' => 'Tess Teacher', 'email' => 'tess@school.test', 'contact' => '09170000000']);
        $this->createTeacher(['email' => 'gone@school.test', 'is_active' => false]);
        User::factory()->registrar()->create(['email' => 'registrar-only@school.test']);

        $listResponse = $this->withToken($this->adminToken)->getJson(self::TEACHERS_URL)->assertOk();

        $this->assertCount(1, $listResponse->json('teachers'));
        $this->assertSame(self::TEACHER_KEYS, array_keys($listResponse->json('teachers.0')));
        $listResponse->assertJsonPath('teachers.0', ['id' => $teacher->id, 'name' => 'Tess Teacher', 'email' => 'tess@school.test', 'contact' => '09170000000']);
    }

    public function test_list_includes_teacher_with_other_roles(): void
    {
        $multiRoleTeacher = User::factory()->admin()->teacher()->create(['email' => 'multi@school.test']);

        $listResponse = $this->withToken($this->adminToken)->getJson(self::TEACHERS_URL)->assertOk();

        $this->assertSame([$multiRoleTeacher->id], array_column($listResponse->json('teachers'), 'id'));
    }

    public function test_list_searches_name_only(): void
    {
        $this->createTeacher(['name' => 'Maria Santos', 'email' => 'zed@school.test']);
        $this->createTeacher(['name' => 'Jose Rizal', 'email' => 'maria.fan@school.test']);

        $nameResponse = $this->withToken($this->adminToken)->getJson(self::TEACHERS_URL.'?search=santos maria')->assertOk();
        $emailResponse = $this->withToken($this->adminToken)->getJson(self::TEACHERS_URL.'?search=school.test')->assertOk();

        $this->assertSame(['Maria Santos'], array_column($nameResponse->json('teachers'), 'name'));
        $this->assertSame(0, $emailResponse->json('meta.total'));
    }

    public function test_list_orders_by_name_then_id(): void
    {
        $this->createTeacher(['name' => 'Zed', 'email' => 'z@school.test']);
        $firstTwin = $this->createTeacher(['name' => 'Amy', 'email' => 'amy1@school.test']);
        $secondTwin = $this->createTeacher(['name' => 'Amy', 'email' => 'amy2@school.test']);

        $listResponse = $this->withToken($this->adminToken)->getJson(self::TEACHERS_URL)->assertOk();

        $this->assertSame([$firstTwin->id, $secondTwin->id], array_slice(array_column($listResponse->json('teachers'), 'id'), 0, 2));
        $this->assertSame('Zed', $listResponse->json('teachers.2.name'));
    }

    public function test_list_paginates_sixteen_teachers(): void
    {
        foreach (range(1, 16) as $teacherNumber) {
            $this->createTeacher(['name' => sprintf('Teacher %02d', $teacherNumber)]);
        }

        $firstPage = $this->withToken($this->adminToken)->getJson(self::TEACHERS_URL)->assertOk();
        $secondPage = $this->withToken($this->adminToken)->getJson(self::TEACHERS_URL.'?page=2')->assertOk();

        $this->assertCount(self::PAGE_SIZE, $firstPage->json('teachers'));
        $this->assertSame(['Teacher 16'], array_column($secondPage->json('teachers'), 'name'));
        $firstPage->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 16]);
    }

    public function test_active_list_is_unpaginated_without_meta(): void
    {
        foreach (range(1, 16) as $teacherNumber) {
            $this->createTeacher(['name' => sprintf('Teacher %02d', $teacherNumber)]);
        }
        $this->createTeacher(['is_active' => false]);

        $activeResponse = $this->withToken($this->adminToken)->getJson(self::ACTIVE_TEACHERS_URL)->assertOk();

        $this->assertCount(16, $activeResponse->json('teachers'));
        $this->assertSame(['teachers'], array_keys($activeResponse->json()));
        $this->assertSame('Teacher 01', $activeResponse->json('teachers.0.name'));
    }

    #[DataProvider('provideInvalidSearches')]
    public function test_list_rejects_invalid_search(string $queryString): void
    {
        $this->withToken($this->adminToken)
            ->getJson(self::TEACHERS_URL.'?'.$queryString)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search']);
    }

    public function test_admin_can_read_both_teacher_routes(): void
    {
        $this->assertEveryRouteIsOkFor($this->adminToken);
    }

    public function test_registrar_can_read_both_teacher_routes(): void
    {
        $this->assertEveryRouteIsOkFor($this->createRegistrarToken());
    }

    public function test_cashier_gets_403_on_teacher_routes(): void
    {
        $this->assertEveryRouteIsForbiddenFor($this->createCashierToken());
    }

    public function test_teacher_gets_403_on_teacher_routes(): void
    {
        $this->assertEveryRouteIsForbiddenFor($this->createTeacherToken());
    }

    public function test_role_less_user_gets_403_on_teacher_routes(): void
    {
        $this->assertEveryRouteIsForbiddenFor($this->createRoleLessToken());
    }

    public function test_guest_gets_401_on_teacher_routes(): void
    {
        foreach (self::TEACHER_ROUTES as $teacherRoute) {
            $this->getJson($teacherRoute)->assertUnauthorized()->assertExactJson(self::UNAUTHENTICATED_BODY);
        }
    }

    private function assertEveryRouteIsOkFor(string $token): void
    {
        foreach (self::TEACHER_ROUTES as $teacherRoute) {
            $this->withToken($token)->getJson($teacherRoute)->assertOk();
        }
    }

    private function assertEveryRouteIsForbiddenFor(string $token): void
    {
        foreach (self::TEACHER_ROUTES as $teacherRoute) {
            $this->withToken($token)->getJson($teacherRoute)->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
        }
    }
}
