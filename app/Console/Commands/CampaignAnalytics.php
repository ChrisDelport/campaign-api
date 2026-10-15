<?php

/**
 * Analytics report command.
 *
 * Usage: php artisan campaigns:analytics {campaign_id} [--field=first_name]
 *
 * Two jobs here:
 *  1. Import health - how many records landed and how many duplicate events
 *     were logged. duplicate_logs is indexed on (campaign_id, user_id) and
 *     created_at, so both counts are cheap even on big campaigns.
 *  2. Custom-field surfacing - the assessment requires custom_fields to be
 *     reportable without schema changes. When --field is passed we report on
 *     those keys; otherwise we auto-detect every key present in the campaign's
 *     JSON blobs and report populated/distinct counts per key.
 *
 * Note on scale: auto-detection loads custom_fields per row client-side. Fine
 * for a CLI report on realistic campaign sizes; if this ever needs to run over
 * millions of rows, switch to MySQL JSON_TABLE or generated columns.
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignData;
use App\Models\DuplicateLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CampaignAnalytics extends Command
{
    protected $signature = 'campaigns:analytics
        {campaign_id : The campaign to report on}
        {--field=* : Surface a custom_fields key across stored records, e.g. --field=first_name}';

    protected $description = 'Report import counts, duplicate activity, and custom-field breakdowns for a campaign';

    public function handle(): int
    {
        $campaign = Campaign::find($this->argument('campaign_id'));

        if ($campaign === null) {
            $this->error('Campaign not found.');

            return self::FAILURE;
        }

        $total = CampaignData::query()->where('campaign_id', $campaign->id)->count();
        $duplicates = DuplicateLog::query()->where('campaign_id', $campaign->id)->count();

        $this->info("Campaign #{$campaign->id}: {$campaign->name}");
        $this->table(['Metric', 'Value'], [
            ['Stored records', $total],
            ['Duplicate events logged', $duplicates],
        ]);

        $this->reportCustomFields($campaign->id);
        $this->reportDuplicateActivity($campaign->id);

        return self::SUCCESS;
    }

    /**
     * Surface custom_fields for analytics, per the assessment requirement.
     *
     * --field=a --field=b pins the reported keys; without it, keys are
     * auto-detected by scanning every JSON blob once. data_get() supports
     * dot notation (--field=user.email), so nested fields surface too.
     *
     * Trade-off: auto-detection and value collection are client-side PHP
     * over the campaign's rows. Fine for a CLI report; for huge datasets,
     * push this to MySQL (JSON_TABLE / generated columns).
     */
    private function reportCustomFields(int $campaignId): void
    {
        $fields = (array) $this->option('field');

        if ($fields === []) {
            // No explicit keys requested: detect the keys actually present
            // by union-ing the key sets of every stored record.
            $fields = CampaignData::query()
                ->where('campaign_id', $campaignId)
                ->get(['custom_fields'])
                ->flatMap(fn ($row) => array_keys((array) $row->custom_fields))
                ->unique()
                ->values()
                ->all();
        }

        // Campaign has no data (or no fields) — nothing to surface.
        if ($fields === []) {
            return;
        }

        $rows = [];

        // One pass per field. Nulls are excluded from "populated" so the
        // ratio measures real signal, not presence of the key.
        foreach ($fields as $field) {
            $values = CampaignData::query()
                ->where('campaign_id', $campaignId)
                ->get(['custom_fields'])
                ->map(fn ($row) => data_get($row->custom_fields, $field))
                ->filter(fn ($value) => $value !== null);

            $populated = $values->count();
            $distinct = $values->unique()->count();

            $rows[] = [
                $field,
                $populated,
                $distinct,
                $populated > 0 ? round($distinct / $populated, 2) : 'n/a',
            ];
        }

        $this->line('Custom fields surfaced (key, populated records, distinct values, distinct/populated ratio):');
        $this->table(['Key', 'Populated', 'Distinct', 'Ratio'], $rows);
    }

    /**
     * Tail of the duplicate audit trail — the 10 most recent events.
     *
     * Served by the created_at index on duplicate_logs, so this stays fast
     * even as the table grows. custom_fields is truncated to keep the CLI
     * table readable; the full payload is in the DB / duplicates.log.
     */
    private function reportDuplicateActivity(int $campaignId): void
    {
        $recent = DuplicateLog::query()
            ->where('campaign_id', $campaignId)
            ->latest()
            ->limit(10)
            ->get(['user_id', 'custom_fields', 'created_at']);

        if ($recent->isEmpty()) {
            return;
        }

        $this->line('Most recent duplicate activity:');

        $this->table(
            ['user_id', 'custom_fields (truncated)', 'logged at'],
            $recent->map(fn ($log): array => [
                $log->user_id,
                Str::limit(json_encode($log->custom_fields), 60),
                Carbon::parse($log->created_at)->format('Y-m-d H:i:s'),
            ])->all(),
        );
    }
}
