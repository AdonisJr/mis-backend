<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListUnpostedEventsRequest;
use App\Http\Resources\UnpostedEventResource;
use App\Support\PendingEventPoster;
use App\Support\UnpostedEventFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnpostedEventController extends Controller
{
    use SearchesPaginatedLists;

    public function listUnpostedEvents(ListUnpostedEventsRequest $request): JsonResponse
    {
        $eventPage = (new UnpostedEventFinder)->paginate((int) ($request->validated('page') ?? 1));

        return response()->json([
            'unposted_events' => UnpostedEventResource::collection($eventPage->getCollection()),
            'meta' => $this->buildPageMeta($eventPage),
        ]);
    }

    public function postPendingEvents(Request $request): JsonResponse
    {
        $postingSummary = (new PendingEventPoster($request->user()))->postAll();

        return response()->json([
            'posted_count' => $postingSummary['posted_count'],
            'failed_count' => $postingSummary['failed_count'],
            'failures' => UnpostedEventResource::collection($postingSummary['failures']),
        ]);
    }

    protected function getSearchColumns(): array
    {
        return [];
    }
}
