<?php

namespace App\Http\Controllers;

use App\Events\AssignRfid;
use App\Http\Requests\RelayRfidScanRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class RfidController extends Controller
{
    public const CHANNEL_CACHE_KEY = 'rfid_channel_opened';

    public const CHANNEL_TTL_SECONDS = 300;

    public const SUCCESS_STATUS = 'success';

    public const SENT_MESSAGE = 'RFID request sent to device.';

    public function requestRfid(RelayRfidScanRequest $request): JsonResponse
    {
        broadcast(new AssignRfid($request->validated('rfid')));

        return response()->json(['status' => self::SUCCESS_STATUS, 'message' => self::SENT_MESSAGE]);
    }

    public function openRfidChannel(): JsonResponse
    {
        Cache::put(self::CHANNEL_CACHE_KEY, true, self::CHANNEL_TTL_SECONDS);

        return response()->json($this->buildChannelState(self::CHANNEL_TTL_SECONDS));
    }

    public function closeRfidChannel(): JsonResponse
    {
        Cache::forget(self::CHANNEL_CACHE_KEY);

        return response()->json($this->buildChannelState(0));
    }

    /**
     * @return array{rfid_channel: array{is_open: bool, expires_in_seconds: int}}
     */
    private function buildChannelState(int $expiresInSeconds): array
    {
        return ['rfid_channel' => ['is_open' => $expiresInSeconds > 0, 'expires_in_seconds' => $expiresInSeconds]];
    }
}
