<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class StudentAccountAccessTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function buildAccountRoutes(): array
    {
        $studentUrl = '/api/mis/students/'.Student::factory()->create()->id.'/mobile-account';
        $sectionId = $this->createSection(['name' => 'Rizal'])->id;

        return [
            ['GET', $studentUrl],
            ['POST', $studentUrl],
            ['PUT', $studentUrl.'/password'],
            ['PUT', $studentUrl.'/deactivate'],
            ['PUT', $studentUrl.'/reactivate'],
            ['POST', '/api/mis/sections/'.$sectionId.'/mobile-accounts'],
        ];
    }

    public function test_guest_gets_401_on_every_account_route(): void
    {
        foreach ($this->buildAccountRoutes() as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized()->assertExactJson(self::UNAUTHENTICATED_BODY);
        }
    }

    public function test_cashier_teacher_accountant_and_student_get_403_on_every_account_route(): void
    {
        $tokens = [
            $this->createCashierToken(),
            $this->createTeacherToken(),
            $this->createTokenFor(User::factory()->accountant()->create()),
            $this->createTokenFor(User::factory()->studentAccount()->create()),
        ];

        foreach ($tokens as $token) {
            foreach ($this->buildAccountRoutes() as [$method, $url]) {
                $this->withToken($token)->json($method, $url)->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
            }
        }
    }

    public function test_admin_and_registrar_can_read_an_account(): void
    {
        $studentUrl = '/api/mis/students/'.Student::factory()->create()->id.'/mobile-account';

        $this->withToken($this->adminToken)->getJson($studentUrl)->assertOk();
        $this->withToken($this->createRegistrarToken())->getJson($studentUrl)->assertOk();
    }
}
