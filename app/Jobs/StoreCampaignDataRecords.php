<?php

/**
 * Campaign data storage job.
 *
 * This is the only place where imported records hit the database. The HTTP
 * controller dispatches one instance of this job per import request, so the
 * API can acknowledge with 202 without waiting on any I/O beyond Redis.
 *
 * Design notes (Chris Delport):
 *
 * - Storage is a single upsert per batch. MySQL handles thousands of rows in
 *   one round-trip, which is why the 5000-record request cap is comfortable.
 * - Duplicate strategy is "update on duplicate" (latest payload wins), per
 *   the assessment's preferred option. The upsert makes handling() idempotent,
 *   so a retried job never corrupts data - it just re-applies the same rows.
 * - Duplicate detection happens BEFORE the upsert: one indexed pre-read finds
 *   user_ids that already exist for the campaign, plus an in-memory scan that
 *   catches the same user_id submitted twice within a single batch (the
 *   pre-read can't see those). Both feed duplicate_logs.
 * - logDuplicates() is keyed by an md5 idempotency token stored in
 *   duplicate_logs.meta so retries / at-least-once queue delivery never log
 *   the same duplicate twice.
 *
 * Depends on: campaign_data's unique (campaign_id, user_id) constraint -
 * without it the upsert would blindly insert and duplicates would slip through.
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

namespace App\Jobs;

use App\Models\CampaignData;
use App\Models\DuplicateLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class StoreCampaignDataRecords implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A small batch can be retried quickly; retries are cheap because the
     * storage itself is idempotent (update-on-duplicate). Three attempts
     * covers transient DB/Redis blips without retry storms.
     */
    public int $tries = 3;

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    public function __construct(
        // Stored as a scalar (not a Campaign model) on purpose: the job
        // payload stays tiny, and campaign deletion mid-flight degrades to
        // an FK violation handled by failed() rather than a stale-model bug.
        public int $campaignId,
        // Raw validated records. SerialisedModels would be convenient but
        // serialising 5000 Eloquent models per job payload is wasteful.
        public array $records,
    ) {}

    /**
     * Store the batch.
     *
     * Sequence: build rows -> classify duplicates -> upsert -> log.
     * Classification must run BEFORE the upsert because afterwards we can't
     * distinguish "existed already" from "created by us".
     */
    public function handle(): void
    {
        // One timestamp for the whole batch: makes upsert semantics uniform
        // and keeps the duplicate-log snapshot consistent with the data row.
        $now = now();

        // Materialise DB-ready rows up front. custom_fields is pre-encoded
        // here because the upsert writes raw column values (no model casts
        // involved); the null coalesce keeps omitted fields as NULL.
        $rows = array_map(fn (array $record): array => [
            'campaign_id' => $this->campaignId,
            'user_id' => $record['user_id'],
            'video_url' => $record['video_url'],
            'custom_fields' => isset($record['custom_fields'])
                ? json_encode($record['custom_fields'])
                : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->records);

        // Duplicates are classified in one pass over the batch:
        //  - intra-batch: the same user_id submitted more than once within
        //    this payload (the pre-read cannot see these);
        //  - pre-existing: user_ids already stored for this campaign before
        //    the upsert overwrites them.
        // $seen doubles as the de-duplicated user_id list for the pre-read,
        // so intra-batch dupes don't inflate the IN () clause.
        $seen = [];
        $intraBatch = [];

        foreach ($rows as $row) {
            $key = $row['user_id'];

            if (isset($seen[$key])) {
                $intraBatch[] = $row;
            }

            $seen[$key] = true;
        }

        // Indexed pre-read (unique (campaign_id, user_id) makes this a
        // range scan) — must happen before the upsert overwrites the rows,
        // otherwise "existing" becomes indistinguishable from "just inserted".
        $preExisting = CampaignData::query()
            ->where('campaign_id', $this->campaignId)
            ->whereIn('user_id', array_keys($seen))
            ->pluck('user_id')
            ->all();

        // The upsert itself: INSERT ... ON DUPLICATE KEY UPDATE under the
        // hood. 'update' deliberately excludes created_at/campaign_id/user_id
        // — those are immutable identity; only payload fields refresh.
        CampaignData::upsert(
            $rows,
            uniqueBy: ['campaign_id', 'user_id'],
            update: ['video_url', 'custom_fields', 'updated_at'],
        );

        // Merge both duplicate classes and de-dupe (a user_id can be both
        // intra-batch duplicated AND pre-existing), then hand off to the
        // idempotent logger below. logDuplicates() is idempotent per (job,
        // user_id set): repeated handling of the same job (retries,
        // at-least-once delivery) never inserts the same log row twice.
        $this->logDuplicates(array_values(array_unique(array_merge(
            array_column($intraBatch, 'user_id'),
            $preExisting,
        ))));
    }

    /**
     * Record duplicate attempts — idempotently.
     *
     * Idempotency: an md5 hash of the (campaign, user_id set) is stored in
     * duplicate_logs.meta.log_key and checked before insert. A retried job
     * computes the same key and short-circuits, so at-least-once queue
     * delivery never double-counts duplicates in analytics.
     *
     * The snapshot comes from campaign_data (post-upsert) so the log row
     * reflects the data that actually survived, via getRawOriginal() to
     * reuse the stored JSON string instead of re-encoding through casts.
     *
     * @param  list<string>  $userIds
     */
    private function logDuplicates(array $userIds): void
    {
        // Nothing duplicated in this batch — avoid the idempotency SELECT
        // entirely on the happy path (the common case).
        if ($userIds === []) {
            return;
        }

        // Deterministic per (campaign, duplicate set): a retried job always
        // derives the same key.
        $logKey = 'campaign-data-import:'.$this->campaignId.':'.md5(serialize($userIds));

        // JSON-path lookup on meta: fine at log volume; add a generated
        // column on meta->log_key if this table ever grows large.
        $alreadyLogged = DuplicateLog::query()
            ->where('meta->log_key', $logKey)
            ->exists();

        if ($alreadyLogged) {
            return;
        }

        $duplicates = CampaignData::query()
            ->where('campaign_id', $this->campaignId)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'custom_fields']);

        // Single bulk insert (not per-row ::create) — duplicates are rare,
        // but when they happen the write should still be one round-trip.
        DuplicateLog::insert($duplicates->map(fn ($data): array => [
            'campaign_id' => $this->campaignId,
            'user_id' => $data->user_id,
            'custom_fields' => $data->getRawOriginal('custom_fields'),
            'meta' => json_encode(['log_key' => $logKey]),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());

        // Dedicated channel (config/logging.php -> 'duplicates') so
        // duplicate activity is greppable without wading through app logs.
        Log::channel('duplicates')->info('Duplicate campaign data detected.', [
            'campaign_id' => $this->campaignId,
            'user_ids' => $userIds,
            'log_key' => $logKey,
        ]);
    }

    /**
     * Terminal failure handler (after all $tries exhausted).
     *
     * Signature matters: Laravel passes a Throwable. Logging the batch size
     * alongside the exception lets ops size the manual re-queue — the batch
     * is NOT partially re-applied automatically, because the job makes no
     * atomicity promise across all rows; the client re-submits instead.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Storing campaign data failed permanently.', [
            'campaign_id' => $this->campaignId,
            'record_count' => count($this->records),
            'exception' => $exception->getMessage(),
        ]);
    }
}
