<?php

/**
 * Append-only record of duplicate import attempts, for reporting.
 *
 * meta.log_key carries an idempotency token so retried queue jobs never log
 * the same duplicate twice. Rows exist purely for audit/analytics - nothing
 * in the import path reads them back.
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateLog extends Model
{
    use HasFactory;

    /** log_key in meta carries the idempotency token used by the job. */
    protected $fillable = ['campaign_id', 'user_id', 'custom_fields', 'meta'];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'meta' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
