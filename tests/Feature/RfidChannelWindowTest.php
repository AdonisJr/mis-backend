<?php

namespace Tests\Feature;

use App\Events\AssignRfid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RfidChannelWindowTest extends TestCase
{
    use RefreshDatabase;

    private const OPEN_URL = '/api/mis/rfid/open';

    private const CLOSE_URL = '/api/mis/rfid/close';

    private const RELAY_URL = '/api/rfid/scan';

    private const TOKEN_NAME = 'test';

    private const UID = '04:A1:B2:C3';

    private const OPEN_BODY = ['rfid_channel' => ['is_open' => true, 'expires_in_seconds' => 300]];

    private const CLOSED_BODY = ['rfid_channel' => ['is_open' => false, 'expires_in_seconds' => 0]];

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([AssignRfid::class]);
    }

    public function test_admin_opens_the_window(): void
    {
        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::OPEN_URL)
            ->assertOk()
            ->assertExactJson(self::OPEN_BODY);

        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertOk();
    }

    public function test_registrar_opens_and_closes_the_window(): void
    {
        $token = $this->createTokenFor(User::factory()->registrar()->create());

        $this->withToken($token)->postJson(self::OPEN_URL)->assertOk()->assertExactJson(self::OPEN_BODY);
        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertOk();

        $this->withToken($token)->postJson(self::CLOSE_URL)->assertOk()->assertExactJson(self::CLOSED_BODY);
        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertForbidden();
    }

    public function test_close_closes_the_relay(): void
    {
        $token = $this->createTokenFor(User::factory()->admin()->create());
        $this->withToken($token)->postJson(self::OPEN_URL)->assertOk();

        $this->withToken($token)
            ->postJson(self::CLOSE_URL)
            ->assertOk()
            ->assertExactJson(self::CLOSED_BODY);

        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertForbidden();
    }

    public function test_close_when_already_closed_returns_200(): void
    {
        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::CLOSE_URL)
            ->assertOk()
            ->assertExactJson(self::CLOSED_BODY);
    }

    public function test_window_expires_at_300_seconds(): void
    {
        $this->freezeSecond();
        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))->postJson(self::OPEN_URL)->assertOk();

        $this->travel(299)->seconds();
        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertOk();

        $this->travel(1)->seconds();
        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertForbidden();
    }

    public function test_reopen_restarts_the_300_seconds(): void
    {
        $this->freezeSecond();
        $token = $this->createTokenFor(User::factory()->admin()->create());
        $this->withToken($token)->postJson(self::OPEN_URL)->assertOk();

        $this->travel(200)->seconds();
        $this->withToken($token)->postJson(self::OPEN_URL)->assertOk()->assertExactJson(self::OPEN_BODY);

        $this->travel(200)->seconds();
        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertOk();

        $this->travel(100)->seconds();
        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertForbidden();
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
