<?php

namespace Tests\Feature;

use App\Events\AssignRfid;
use App\Http\Controllers\RfidController;
use App\Http\Requests\RelayRfidScanRequest;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class RfidRelayTest extends TestCase
{
    use RefreshDatabase;

    private const RELAY_URL = '/api/rfid/scan';

    private const UID = '04:A1:B2:C3';

    private const THROTTLE_LIMIT = 60;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([AssignRfid::class]);
    }

    public function test_relay_returns_403_while_window_is_closed(): void
    {
        $this->post(self::RELAY_URL, ['rfid' => self::UID])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['status' => 'error', 'message' => RelayRfidScanRequest::CHANNEL_CLOSED_MESSAGE]);

        Event::assertNotDispatched(AssignRfid::class);
    }

    public function test_relay_broadcasts_the_normalised_uid_while_window_is_open(): void
    {
        $this->openWindow();

        $this->post(self::RELAY_URL, ['rfid' => ' 04:a1:b2:c3 '])
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'message' => RfidController::SENT_MESSAGE]);

        Event::assertDispatched(AssignRfid::class, fn (AssignRfid $event) => $event->message === self::UID);
    }

    public function test_relay_accepts_a_json_body(): void
    {
        $this->openWindow();

        $this->postJson(self::RELAY_URL, ['rfid' => self::UID])
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'message' => RfidController::SENT_MESSAGE]);

        Event::assertDispatched(AssignRfid::class, fn (AssignRfid $event) => $event->message === self::UID);
    }

    public function test_relay_returns_422_for_missing_or_invalid_rfid(): void
    {
        $this->openWindow();

        foreach ([[], ['rfid' => ['x']], ['rfid' => '   '], ['rfid' => Str::repeat('A', 256)]] as $body) {
            $this->post(self::RELAY_URL, $body)
                ->assertUnprocessable()
                ->assertHeader('Content-Type', 'application/json')
                ->assertJsonValidationErrors(['rfid']);
        }

        Event::assertNotDispatched(AssignRfid::class);
    }

    public function test_relay_checks_the_window_before_the_body(): void
    {
        $this->post(self::RELAY_URL, ['rfid' => ['x']])
            ->assertForbidden()
            ->assertExactJson(['status' => 'error', 'message' => RelayRfidScanRequest::CHANNEL_CLOSED_MESSAGE]);
    }

    public function test_relay_is_throttled_to_60_requests_per_minute(): void
    {
        $this->freezeSecond();

        for ($attempt = 0; $attempt < self::THROTTLE_LIMIT; $attempt++) {
            $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertForbidden();
        }

        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertTooManyRequests();

        $this->travel(59)->seconds();
        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertTooManyRequests();

        $this->travel(1)->seconds();
        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertForbidden();
    }

    public function test_assign_rfid_event_broadcasts_now_on_the_private_rfid_channel(): void
    {
        $event = new AssignRfid(self::UID);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertCount(1, $event->broadcastOn());
        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame('private-rfid', $event->broadcastOn()[0]->name);
        $this->assertSame(self::UID, $event->message);
    }

    private function openWindow(): void
    {
        Cache::put(RfidController::CHANNEL_CACHE_KEY, true, RfidController::CHANNEL_TTL_SECONDS);
    }
}
