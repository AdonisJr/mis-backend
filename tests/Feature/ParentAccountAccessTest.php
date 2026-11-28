<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class ParentAccountAccessTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    private const PARENT_ROUTES = [
        ['GET', '/api/mis/parents'],
        ['POST', '/api/mis/parents'],
        ['PUT', '/api/mis/parents/1/password'],
        ['PUT', '/api/mis/parents/1/deactivate'],
        ['PUT', '/api/mis/parents/1/reactivate'],
        ['GET', '/api/mis/students/1/parents'],
        ['POST', '/api/mis/students/1/parents'],
        ['DELETE', '/api/mis/students/1/parents/1'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    public function test_guest_gets_401_on_every_parent_route(): void
    {
        foreach (self::PARENT_ROUTES as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized()->assertExactJson(self::UNAUTHENTICATED_BODY);
        }
    }

    public function test_other_roles_get_403_on_every_parent_route(): void
    {
        $tokens = [
            $this->createCashierToken(),
            $this->createTeacherToken(),
            $this->createTokenFor(User::factory()->accountant()->create(['email' => 'accountant@school.test'])),
            $this->createTokenFor(User::factory()->studentAccount()->create()),
            $this->createTokenFor(User::factory()->parentAccount()->create()),
        ];

        foreach ($tokens as $token) {
            $this->app['auth']->forgetGuards();

            foreach (self::PARENT_ROUTES as [$method, $uri]) {
                $this->withToken($token)->json($method, $uri)->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
            }
        }
    }

    public function test_admin_and_registrar_can_use_the_parent_routes(): void
    {
        $student = Student::factory()->create();
        $numbers = ['09170000011', '09170000012'];

        foreach ([$this->adminToken, $this->createRegistrarToken()] as $index => $token) {
            $this->app['auth']->forgetGuards();

            $this->withToken($token)->getJson('/api/mis/parents')->assertOk();
            $this->withToken($token)->getJson('/api/mis/students/'.$student->id.'/parents')->assertOk();
            $this->withToken($token)->postJson('/api/mis/parents', ['name' => 'Parent '.$index, 'mobile_number' => $numbers[$index]])->assertCreated();
        }
    }
}
