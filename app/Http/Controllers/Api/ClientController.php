<?php

/**
 * Client HTTP endpoints (list + create).
 *
 * Clients are the top of the ownership chain: Client -> Campaigns -> Data.
 * Kept minimal because the assessment's focus is the campaign/import side,
 * but the dashboard needs at least list + create, so both live here.
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ClientController extends Controller
{
    /**
     * GET /api/clients — list all clients, alphabetically.
     *
     * Ordered by name (not id) because the primary consumer is the
     * dashboard's client dropdown, where alphabetical beats insertion
     * order for usability. No pagination yet: client counts are in the
     * tens, not millions — add cursor pagination before this ever matters.
     */
    public function index(): AnonymousResourceCollection
    {
        return ClientResource::collection(Client::query()->orderBy('name')->get());
    }

    /**
     * POST /api/clients — create a client company.
     *
     * StoreClientRequest has already validated that 'name' is present and
     * within 255 chars; validated() only contains fields that passed rules,
     * which prevents mass-assignment of anything unexpected.
     *
     * Returns 201 (not 200) because a new resource was created — clients
     * can use the returned id immediately for POST /api/campaigns.
     */
    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = Client::create($request->validated());

        return ClientResource::make($client)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
