<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class AssignRfid implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const CHANNEL_NAME = 'rfid';

    public function __construct(public string $message) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::CHANNEL_NAME)];
    }
}
