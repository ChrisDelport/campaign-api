<?php

/*
|--------------------------------------------------------------------------
| API routes - Campaign Manager
|--------------------------------------------------------------------------
|
| Chris Delport (chrisd@chrisd.co.za)
|
| Ownership chain: Client -> Campaign -> CampaignData.
|
| Reading order for a walkthrough:
|   1. clients        - top of the chain, list + create
|   2. campaigns      - belongs to a client
|   3. campaigns/data - the async import endpoint (202 + queued job)
|
| All state-changing routes are POST-only per the assessment; listing
| endpoints exist because the Next.js dashboard needs them.
*/

use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\ClientController;
use Illuminate\Support\Facades\Route;

Route::apiResource('clients', ClientController::class)->only(['index', 'store']);
Route::apiResource('campaigns', CampaignController::class)->only(['index', 'store']);
Route::post('/campaigns/{campaign}/data', [CampaignController::class, 'importData']);
