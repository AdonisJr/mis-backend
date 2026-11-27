<?php

namespace Tests\Feature;

use App\Events\AssignRfid;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RfidAccessTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const UID = '04:A1:B2:C3';

    private const RELAY_URL = '/api/rfid/scan';

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([AssignRfid::class]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideRfidAdminRoutes(): array
    {
        return [
            'open' => ['POST', '/api/mis/rfid/open'],
            'close' => ['POST', '/api/mis/rfid/close'],
            'assign' => ['PUT', '/api/mis/students/rfid/%d'],
            'unassign' => ['DELETE', '/api/mis/students/rfid/%d'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideExcludedRoles(): array
    {
        return [
            'cashier' => [Role::CASHIER],
            'accountant' => [Role::ACCOUNTANT],
            'teacher' => [Role::TEACHER],
            'student' => [Role::STUDENT],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRegistryRoles(): array
    {
        return [
            'admin' => [Role::ADMIN],
            'registrar' => [Role::REGISTRAR],
        ];
    }

    #[DataProvider('provideRfidAdminRoutes')]
    public function test_guest_gets_401_on_rfid_admin_routes(string $method, string $uriPattern): void
    {
        $student = Student::factory()->create();

        $this->json($method, sprintf($uriPattern, $student->id), ['rfid' => self::UID])
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    #[DataProvider('provideExcludedRoles')]
    public function test_excluded_role_gets_403_on_every_rfid_admin_route(string $roleName): void
    {
        $student = Student::factory()->create();
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => $roleName]));
        $token = $this->createTokenFor($user);

        foreach (self::provideRfidAdminRoutes() as [$method, $uriPattern]) {
            $this->withToken($token)
                ->json($method, sprintf($uriPattern, $student->id), ['rfid' => self::UID])
                ->assertForbidden()
                ->assertExactJson(self::FORBIDDEN_BODY);
        }

        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertForbidden();
        $this->assertNull($student->fresh()->rfid);
    }

    public function test_role_less_user_gets_403_on_every_rfid_admin_route(): void
    {
        $student = Student::factory()->create();
        $token = $this->createTokenFor(User::factory()->create());

        foreach (self::provideRfidAdminRoutes() as [$method, $uriPattern]) {
            $this->withToken($token)
                ->json($method, sprintf($uriPattern, $student->id), ['rfid' => self::UID])
                ->assertForbidden()
                ->assertExactJson(self::FORBIDDEN_BODY);
        }

        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertForbidden();
        $this->assertNull($student->fresh()->rfid);
    }

    #[DataProvider('provideRegistryRoles')]
    public function test_admin_and_registrar_reach_every_rfid_admin_route(string $roleName): void
    {
        $student = Student::factory()->create();
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => $roleName]));
        $token = $this->createTokenFor($user);

        foreach (self::provideRfidAdminRoutes() as [$method, $uriPattern]) {
            $this->withToken($token)
                ->json($method, sprintf($uriPattern, $student->id), ['rfid' => self::UID])
                ->assertOk();
        }
    }

    public function test_role_less_user_gets_403_before_404_on_assign_and_unassign(): void
    {
        $token = $this->createTokenFor(User::factory()->create());

        $this->withToken($token)
            ->putJson('/api/mis/students/rfid/'.self::MISSING_ID, ['rfid' => self::UID])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->withToken($token)
            ->deleteJson('/api/mis/students/rfid/'.self::MISSING_ID)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
