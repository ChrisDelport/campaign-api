<?php

/**
 * Feature tests for the campaign API and import pipeline.
 *
 * Chris Delport (chrisd@chrisd.co.za)
 *
 * Coverage map, in walkthrough order:
 *  - clients: create / validate / list
 *  - campaigns: create (incl. optional end_date) / validation datasets
 *  - import: 202 ack, queue asserted, nothing persisted synchronously;
 *    sync 422 on bad payloads
 *  - job behaviour: upsert semantics, cross-batch and intra-batch duplicate
 *    detection, retry idempotency, arbitrary custom_fields
 *  - analytics: success (incl. --field surfacing) and unknown-campaign failure
 *
 * The job tests call handle() directly on purpose - that tests the actual
 * storage logic against a real (sqlite) database rather than a mocked
 * repository, while the HTTP tests use Queue::fake() to assert the async
 * boundary. Between the two layers, everything is covered.
 */

use App\Jobs\StoreCampaignDataRecords;
use App\Models\Campaign;
use App\Models\CampaignData;
use App\Models\Client;
use App\Models\DuplicateLog;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->client = Client::factory()->create();
});

it('creates a client', function () {
    postJson('/api/clients', ['name' => 'Acme Corp'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Acme Corp');

    expect(Client::query()->where('name', 'Acme Corp')->exists())->toBeTrue();
});

it('rejects a client without a name', function () {
    postJson('/api/clients', ['name' => ''])->assertUnprocessable();
});

it('lists clients', function () {
    Client::factory()->create(['name' => 'Beta LLC']);

    getJson('/api/clients')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Beta LLC');
});

it('creates a campaign and returns it as a resource', function () {
    $response = postJson('/api/campaigns', [
        'client_id' => $this->client->id,
        'name' => 'Summer Sale',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Summer Sale')
        ->assertJsonPath('data.client_id', $this->client->id)
        ->assertJsonPath('data.start_date', '2026-01-01');

    expect(Campaign::query()->where('name', 'Summer Sale')->exists())->toBeTrue();
});

it('creates a campaign without an optional end_date', function () {
    postJson('/api/campaigns', [
        'client_id' => $this->client->id,
        'name' => 'Open-ended',
        'start_date' => '2026-01-01',
    ])->assertCreated()
        ->assertJsonPath('data.end_date', null);
});

it('rejects invalid campaign payloads', function (array $payload) {
    postJson('/api/campaigns', $payload)->assertUnprocessable();
})->with([
    'missing name' => fn () => [
        'client_id' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ],
    'end before start' => fn () => [
        'client_id' => 1,
        'name' => 'Bad',
        'start_date' => '2026-01-31',
        'end_date' => '2026-01-01',
    ],
    'unknown client' => fn () => [
        'client_id' => 999999,
        'name' => 'Ghost',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ],
]);

it('accepts campaign data imports asynchronously and returns 202', function () {
    Queue::fake();

    $campaign = Campaign::factory()->forClient($this->client->id)->create();

    $response = postJson("/api/campaigns/{$campaign->id}/data", [
        [
            'user_id' => 'user-123',
            'video_url' => 'https://example.com/video.mp4',
            'custom_fields' => ['first_name' => 'John'],
        ],
        [
            'user_id' => 'user-456',
            'video_url' => 'https://example.com/video2.mp4',
        ],
    ]);

    $response->assertAccepted()
        ->assertJsonPath('campaign_id', $campaign->id)
        ->assertJsonPath('queued', 2);

    Queue::assertPushed(StoreCampaignDataRecords::class, 1);

    expect(CampaignData::query()->count())->toBe(0);
});

it('rejects invalid import payloads synchronously', function () {
    Queue::fake();
    $campaign = Campaign::factory()->forClient($this->client->id)->create();

    postJson("/api/campaigns/{$campaign->id}/data", [
        ['user_id' => '', 'video_url' => 'not-a-url'],
    ])->assertUnprocessable();

    Queue::assertNothingPushed();
});

it('detects duplicates inside a single batch', function () {
    $campaign = Campaign::factory()->forClient($this->client->id)->create();

    (new StoreCampaignDataRecords($campaign->id, [
        ['user_id' => 'dup-1', 'video_url' => 'https://example.com/1.mp4'],
        ['user_id' => 'dup-1', 'video_url' => 'https://example.com/2.mp4'],
    ]))->handle();

    expect(CampaignData::query()->where('campaign_id', $campaign->id)->count())->toBe(1)
        ->and(DuplicateLog::query()->where('user_id', 'dup-1')->count())->toBe(1);
});

it('does not double-log duplicates when a job is retried', function () {
    $campaign = Campaign::factory()->forClient($this->client->id)->create();

    CampaignData::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => 'retry-1',
        'video_url' => 'https://example.com/old.mp4',
    ]);

    $job = new StoreCampaignDataRecords($campaign->id, [
        ['user_id' => 'retry-1', 'video_url' => 'https://example.com/new.mp4'],
    ]);

    $job->handle(); // attempt 1: logs the duplicate
    $job->handle(); // simulated retry: must not log again

    expect(DuplicateLog::query()->where('user_id', 'retry-1')->count())->toBe(1);
});

it('stores records and detects duplicates via upsert', function () {
    $campaign = Campaign::factory()->forClient($this->client->id)->create();

    $records = [
        ['user_id' => 'user-1', 'video_url' => 'https://example.com/a.mp4', 'custom_fields' => ['first_name' => 'Jane']],
        ['user_id' => 'user-1', 'video_url' => 'https://example.com/b.mp4', 'custom_fields' => ['first_name' => 'Janet']],
        ['user_id' => 'user-2', 'video_url' => 'https://example.com/c.mp4'],
    ];

    // Two separate batches importing the same user simulates a re-import.
    (new StoreCampaignDataRecords($campaign->id, [$records[0], $records[2]]))->handle();
    (new StoreCampaignDataRecords($campaign->id, [$records[1]]))->handle();

    expect(CampaignData::query()->where('campaign_id', $campaign->id)->count())->toBe(2)
        ->and(CampaignData::query()->where('user_id', 'user-1')->value('video_url'))->toBe('https://example.com/b.mp4')
        ->and(DuplicateLog::query()->where('campaign_id', $campaign->id)->where('user_id', 'user-1')->count())->toBe(1)
        ->and(DuplicateLog::query()->where('user_id', 'user-2')->exists())->toBeFalse();
});

it('supports arbitrary custom fields without schema changes', function () {
    $campaign = Campaign::factory()->forClient($this->client->id)->create();

    (new StoreCampaignDataRecords($campaign->id, [
        ['user_id' => 'user-9', 'video_url' => 'https://example.com/v.mp4', 'custom_fields' => ['anything' => ['nested' => true], 'n' => 42]],
    ]))->handle();

    $fields = CampaignData::query()->where('user_id', 'user-9')->value('custom_fields');

    expect($fields['anything']['nested'])->toBeTrue()
        ->and($fields['n'])->toBe(42);
});

it('reports analytics including custom-field surfacing', function () {
    $campaign = Campaign::factory()->forClient($this->client->id)->create();
    CampaignData::factory()->create([
        'campaign_id' => $campaign->id,
        'custom_fields' => ['first_name' => 'Ada', 'plan' => 'pro'],
    ]);
    CampaignData::factory()->create([
        'campaign_id' => $campaign->id,
        'custom_fields' => ['first_name' => 'Linus'],
    ]);
    DuplicateLog::factory()->count(2)->create(['campaign_id' => $campaign->id]);

    $this->artisan('campaigns:analytics', ['campaign_id' => $campaign->id])
        ->expectsOutputToContain('Custom fields surfaced')
        ->assertSuccessful();

    $this->artisan('campaigns:analytics', ['campaign_id' => $campaign->id, '--field' => 'plan'])
        ->expectsTable(
            ['Key', 'Populated', 'Distinct', 'Ratio'],
            [['plan', 1, 1, 1.0]],
        )->assertSuccessful();
});

it('fails analytics for unknown campaigns', function () {
    $this->artisan('campaigns:analytics', ['campaign_id' => 999999])->assertFailed();
});
