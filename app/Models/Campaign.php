<?php

/**
 * Campaign model - belongs to a Client, owns data records and duplicate logs.
 *
 * start_date / end_date are cast to dates so resources can emit clean
 * Y-m-d strings; end_date is nullable (open-ended campaigns).
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    /** Mass-assignment allow-list mirrors StoreCampaignRequest exactly. */
    protected $fillable = ['client_id', 'name', 'start_date', 'end_date'];

    /** Dates as Carbon so resources emit clean Y-m-d; end_date is nullable. */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    /** Owning client. FK cascade at the DB, not via Eloquent events. */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Imported personalised-video records for this campaign. */
    public function data(): HasMany
    {
        return $this->hasMany(CampaignData::class);
    }

    /** Append-only duplicate audit trail (populated by the queue job). */
    public function duplicateLogs(): HasMany
    {
        return $this->hasMany(DuplicateLog::class);
    }
}
