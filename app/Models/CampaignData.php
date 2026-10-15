<?php

/**
 * One personalised video record within a campaign.
 *
 * The table name is explicit (campaign_data, not campaign_datas) and the
 * (campaign_id, user_id) unique constraint on it is what makes the upsert
 * strategy in StoreCampaignDataRecords work - treat that constraint as part
 * of this model's contract.
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignData extends Model
{
    use HasFactory;

    /** Explicit table name — pluralisation of campaign_data would fail. */
    protected $table = 'campaign_data';

    /** Mirrors the job's upsert column set; nothing else is writable. */
    protected $fillable = ['campaign_id', 'user_id', 'video_url', 'custom_fields'];

    /**
     * custom_fields as array: arbitrary JSON in, array out, re-encoded on
     * write via casts. This is the whole reason no migrations are needed
     * when clients add new field names.
     */
    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
        ];
    }

    /** Owning campaign (with the unique campaign_id+user_id constraint). */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
