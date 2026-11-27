<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RfidChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH_URL = '/api/broadcasting/auth';

    private const TOKEN_NAME = 'test';

    private const SOCKET_ID = '1234.5678';

    private const CHANNEL = 'private-rfid';

    private const PUSHER_KEY = 'test-key';

    private const PUSHER_SECRET = 'test-secret';

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    protected function setUp(): void
    {
        parent::setUp();

        // the boot-time null broadcaster authorises nobody and refuses nobody, so the pusher driver is rebuilt with test keys
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => self::PUSHER_KEY,
            'broadcasting.connections.pusher.secret' => self::PUSHER_SECRET,
            'broadcasting.connections.pusher.app_id' => 'test-app',
        ]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    public function test_admin_is_authorised_for_the_private_rfid_channel(): void
    {
        $this->assertAuthorised(User::factory()->admin()->create());
    }

    public function test_registrar_is_authorised_for_the_private_rfid_channel(): void
    {
        $this->assertAuthorised(User::factory()->registrar()->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRefusedRoles(): array
    {
        return [
            'cashier' => [Role::CASHIER],
            'accountant' => [Role::ACCOUNTANT],
            'teacher' => [Role::TEACHER],
            'student' => [Role::STUDENT],
        ];
    }

    #[DataProvider('provideRefusedRoles')]
    public function test_other_roles_are_refused(string $roleName): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => $roleName]));

        $this->requestAuth($user)->assertForbidden();
    }

    public function test_role_less_user_is_refused(): void
    {
        $this->requestAuth(User::factory()->create())->assertForbidden();
    }

    public function test_guest_gets_401(): void
    {
        $this->post(self::AUTH_URL, ['socket_id' => self::SOCKET_ID, 'channel_name' => self::CHANNEL])
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_unknown_channel_is_refused_for_admin(): void
    {
        $this->requestAuth(User::factory()->admin()->create(), 'private-other')->assertForbidden();
    }

    private function assertAuthorised(User $user): void
    {
        $signature = hash_hmac('sha256', self::SOCKET_ID.':'.self::CHANNEL, self::PUSHER_SECRET);

        $this->requestAuth($user)
            ->assertOk()
            ->assertExactJson(['auth' => self::PUSHER_KEY.':'.$signature]);
    }

    private function requestAuth(User $user, string $channelName = self::CHANNEL): TestResponse
    {
        return $this->withToken($user->createToken(self::TOKEN_NAME)->plainTextToken)
            ->post(self::AUTH_URL, ['socket_id' => self::SOCKET_ID, 'channel_name' => $channelName]);
    }
}
