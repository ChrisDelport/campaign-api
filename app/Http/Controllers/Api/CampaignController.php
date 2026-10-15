<?php

/**
 * Campaign HTTP endpoints.
 *
 * Deliberately thin: validation lives in the FormRequests, serialisation in
 * the API Resources, and persistence in the queued job. The controller only
 * orchestrates - which keeps it readable in a walkthrough and trivial to test.
 *
 * Status codes are chosen for REST correctness:
 *  - store:       201 Created (resource returned in the Location-worthy body)
 *  - importData:  202 Accepted - the work hasn't happened yet, only queued.
 *                 Persisting synchronously here would be the easy way to
 *                 fail the assessment's performance requirement.
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportCampaignDataRequest;
use App\Http\Requests\StoreCampaignRequest;
use App\Http\Resources\CampaignResource;
use App\Jobs\StoreCampaignDataRecords;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CampaignController extends Controller
{
    /**
     * GET /api/campaigns — list campaigns, newest first.
     *
     * with('client') eager-loads the relationship to avoid an N+1: without
     * it, serialising N campaigns through CampaignResource would fire N
     * extra queries if/when the resource exposes client data.
     */
    public function index(): AnonymousResourceCollection
    {
        return CampaignResource::collection(
            Campaign::query()->with('client')->latest()->get(),
        );
    }

    /**
     * POST /api/campaigns — create a campaign for a client.
     *
     * StoreCampaignRequest has validated client_id (exists), name, and the
     * date pair (end_date optional, never before start_date). Creating is
     * synchronous and cheap — a single INSERT — so no queueing is needed
     * here; the async path is reserved for bulk data imports.
     */
    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $campaign = Campaign::create($request->validated());

        return CampaignResource::make($campaign)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * POST /api/campaigns/{campaign}/data — bulk import, the hot path.
     *
     * Three steps, none of which touch the MySQL write path:
     *  1. Route-model binding resolves {campaign} (404s if unknown).
     *  2. ImportCampaignDataRequest has already normalised the bare JSON
     *     array into 'records' and validated shape/URLs/caps synchronously —
     *     bad payloads are rejected here, before any queue traffic.
     *  3. The job is serialised (payload only — no Eloquent models) and
     *     pushed to Redis. This is the only I/O of consequence.
     *
     * The 'queued' count in the response gives the caller immediate,
     * deterministic feedback about what was accepted — useful for client
     *-side reconciliation since processing is asynchronous.
     */
    public function importData(Campaign $campaign, ImportCampaignDataRequest $request): JsonResponse
    {
        $records = $request->json('records');

        StoreCampaignDataRecords::dispatch($campaign->id, $records);

        return response()->json([
            'message' => 'Accepted for processing.',
            'campaign_id' => $campaign->id,
            'queued' => count($records),
        ], Response::HTTP_ACCEPTED);
    }
}
