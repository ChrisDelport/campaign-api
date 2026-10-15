<?php

/**
 * Campaign API Resource.
 *
 * Keeps dates as plain Y-m-d strings (not ISO timestamps) - the frontend
 * renders date inputs and comparing "2026-01-01" style strings is what the
 * API consumers actually want.
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

namespace App\Http\Resources;

use App\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Campaign $resource
 * @property int $id
 * @property int $client_id
 * @property string $name
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CampaignResource extends JsonResource
{
    /**
     * Serialise a campaign.
     *
     * Dates as Y-m-d strings (consumer-friendly for date inputs and
     * sorting); nulls pass through for end_date/created_at so the JSON
     * shape stays stable for open-ended campaigns.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'name' => $this->name,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
