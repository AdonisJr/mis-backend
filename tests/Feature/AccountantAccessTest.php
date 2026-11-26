<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\InteractsWithPayments;
use Tests\TestCase;

class AccountantAccessTest extends TestCase
{
    use InteractsWithPayments;
    use RefreshDatabase;

    private const LOGIN_URL = '/api/mis/login';

    private const USER_URL = '/api/mis/user';

    private const LOGOUT_URL = '/api/mis/logout';

    private const EMPLOYEES_URL = '/api/mis/employees';

    private const MIS_URL = '/api/mis';

    private const ACCOUNTANT_PASSWORD = 'secret-pass-1';

    private const SESSION_URIS = ['api/mis/login', 'api/mis/user', 'api/mis/logout'];

    private const ACCOUNTANT_ROUTES = [
        'GET api/mis/school-years',
        'GET api/mis/school-years/get-active',
        'GET api/mis/school-years/{id}/terms',
        'GET api/mis/terms/get-active',
        'GET api/mis/grade-levels',
        'GET api/mis/assessments',
        'GET api/mis/assessments/{id}',
        'GET api/mis/assessments/{id}/history',
        'GET api/mis/payments',
        'GET api/mis/payments/ledger',
        'GET api/mis/payments/recorders',
        'GET api/mis/payments/{id}/receipt',
        'GET api/mis/payment-holds',
        'GET api/mis/payment-holds/check',
        'GET api/mis/accounts',
        'GET api/mis/accounts/{id}',
        'POST api/mis/accounts',
        'PUT api/mis/accounts/{id}',
        'PUT api/mis/accounts/{id}/deactivate',
        'PUT api/mis/accounts/{id}/reactivate',
        'DELETE api/mis/accounts/{id}',
        'GET api/mis/journal-entries',
        'GET api/mis/journal-entries/{id}',
        'POST api/mis/journal-entries',
        'PUT api/mis/journal-entries/{id}',
        'DELETE api/mis/journal-entries/{id}',
        'POST api/mis/journal-entries/{id}/post',
        'POST api/mis/journal-entries/{id}/reverse',
        'GET api/mis/general-ledger',
        'GET api/mis/posting-settings',
        'PUT api/mis/posting-settings',
        'GET api/mis/unposted-events',
        'POST api/mis/unposted-events/post',
    ];

    // every api/mis route except the session ones and the accountant routes; a new route the accountant can reach fails the 403 loop first
    private const CHECKED_ROUTE_COUNT = 115;

    /** @var array<string, mixed> */
    private array $assessment;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
        $this->assessment = $this->assessForPayments('5000.00', ['2030-08-15']);
        $this->payment = Payment::factory()->create(['assessment_id' => $this->assessment['id']]);
        foreach (Role::EMPLOYEE_ROLES as $roleName) {
            Role::firstOrCreate(['name' => $roleName]);
        }
    }

    public function test_accountant_can_log_in_fetch_user_and_log_out(): void
    {
        $this->createAccountant(['password' => self::ACCOUNTANT_PASSWORD]);

        $loginResponse = $this->postJson(self::LOGIN_URL, ['email' => 'accountant@school.test', 'password' => self::ACCOUNTANT_PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.roles', [Role::ACCOUNTANT]);
        $accountantToken = $loginResponse->json('token');

        $this->sendAs($accountantToken, 'GET', self::USER_URL)
            ->assertOk()
            ->assertJsonPath('user.email', 'accountant@school.test')
            ->assertJsonPath('user.roles', [Role::ACCOUNTANT]);
        $this->sendAs($accountantToken, 'POST', self::LOGOUT_URL)->assertNoContent();
        $this->assertSame(0, User::where('email', 'accountant@school.test')->firstOrFail()->tokens()->count());
    }

    public function test_admin_creates_employee_with_accountant_role(): void
    {
        $this->sendAs($this->adminToken, 'POST', self::EMPLOYEES_URL, [
            'name' => 'Ana Ledger',
            'email' => 'ana.ledger@school.test',
            'contact' => '09171234567',
            'password' => self::ACCOUNTANT_PASSWORD,
            'password_confirmation' => self::ACCOUNTANT_PASSWORD,
            'roles' => [Role::ACCOUNTANT],
        ])
            ->assertCreated()
            ->assertJsonPath('employee.roles', [Role::ACCOUNTANT]);

        $this->assertSame([Role::ACCOUNTANT], User::where('email', 'ana.ledger@school.test')->firstOrFail()->roles()->pluck('name')->all());
    }

    public function test_admin_adds_accountant_role_on_update(): void
    {
        $targetEmployee = User::factory()->registrar()->create(['email' => 'target.registrar@school.test']);

        $this->sendAs($this->adminToken, 'PUT', self::EMPLOYEES_URL.'/'.$targetEmployee->id, [
            'name' => $targetEmployee->name,
            'email' => 'target.registrar@school.test',
            'contact' => '09171234567',
            'roles' => [Role::ACCOUNTANT, Role::REGISTRAR],
        ])
            ->assertOk()
            ->assertJsonPath('employee.roles', [Role::ACCOUNTANT, Role::REGISTRAR]);
    }

    public function test_employee_list_filters_by_accountant_role(): void
    {
        $accountantUser = $this->createAccountant();
        User::factory()->registrar()->create(['email' => 'registrar@school.test']);

        $listResponse = $this->sendAs($this->adminToken, 'GET', self::EMPLOYEES_URL.'?'.http_build_query(['role' => Role::ACCOUNTANT]))->assertOk();

        $this->assertSame([$accountantUser->email], array_column($listResponse->json('employees'), 'email'));
    }

    public function test_accountant_can_read_billing_and_picker_routes(): void
    {
        $accountantToken = $this->createTokenFor($this->createAccountant());
        $assessmentId = $this->assessment['id'];
        $scopeQuery = http_build_query(['student_id' => $this->assessment['student_id'], 'school_year_id' => $this->assessment['school_year_id']]);
        $readUris = [
            '/assessments?'.http_build_query(['school_year_id' => $this->assessment['school_year_id']]),
            '/assessments/'.$assessmentId,
            '/assessments/'.$assessmentId.'/history',
            '/payments',
            '/payments/ledger?'.$scopeQuery,
            '/payments/recorders',
            '/payments/'.$this->payment->id.'/receipt',
            '/payment-holds',
            '/payment-holds/check?'.$scopeQuery,
            '/school-years',
            '/school-years/get-active',
            '/school-years/'.$this->schoolYear->id.'/terms',
            '/terms/get-active',
            '/grade-levels',
        ];

        foreach ($readUris as $readUri) {
            $this->sendAs($accountantToken, 'GET', self::MIS_URL.$readUri)->assertOk();
        }
    }

    public function test_accountant_gets_403_on_every_other_mis_route(): void
    {
        $accountantToken = $this->createTokenFor($this->createAccountant());
        $checkedRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/mis/'))
            ->reject(fn ($route) => in_array($route->uri(), self::SESSION_URIS, true))
            ->reject(fn ($route) => in_array($route->methods()[0].' '.$route->uri(), self::ACCOUNTANT_ROUTES, true));

        foreach ($checkedRoutes as $route) {
            $this->sendAs($accountantToken, $route->methods()[0], '/'.str_replace(['{id}', '{quarter}', '{activityId}'], '1', $route->uri()))
                ->assertForbidden()
                ->assertExactJson(self::FORBIDDEN_BODY);
        }

        $this->assertCount(self::CHECKED_ROUTE_COUNT, $checkedRoutes);
    }

    public function test_accountant_reachable_routes_match_the_list(): void
    {
        // the school-year list URI is shared with its POST create route, so reachability is read from the role middleware
        $accountantRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/mis/'))
            ->reject(fn ($route) => in_array($route->uri(), self::SESSION_URIS, true))
            ->filter(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($middleware) => $this->isRoleListWithAccountant($middleware)));

        $this->assertCount(count(self::ACCOUNTANT_ROUTES), $accountantRoutes);

        foreach ($accountantRoutes as $route) {
            $this->assertContains($route->methods()[0].' '.$route->uri(), self::ACCOUNTANT_ROUTES);
        }
    }

    public function test_accountant_with_cashier_role_keeps_cashier_access(): void
    {
        $mixedToken = $this->createTokenFor(User::factory()->accountant()->cashier()->create(['email' => 'mixed@school.test']));

        $this->sendAs($mixedToken, 'GET', self::PAYMENTS_URL)->assertOk();
        $this->sendAs($mixedToken, 'GET', self::MIS_URL.'/accounts')->assertOk();
        $this->sendAs($mixedToken, 'GET', self::EMPLOYEES_URL)->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
    }

    private function isRoleListWithAccountant(mixed $middleware): bool
    {
        if (! is_string($middleware) || ! str_starts_with($middleware, 'role:')) {
            return false;
        }

        $roleList = explode(',', substr($middleware, strlen('role:')));

        return in_array(Role::ACCOUNTANT, $roleList, true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAccountant(array $overrides = []): User
    {
        return User::factory()->accountant()->create(['email' => 'accountant@school.test', ...$overrides]);
    }
}
