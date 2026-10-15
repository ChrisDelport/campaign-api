<?php

/**
 * Client API Resource.
 *
 * @author Chris Delport <chrisd@chrisd.co.za>
 */

namespace App\Http\Resources;

use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Client $resource
 * @property int $id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ClientResource extends JsonResource
{
    /**
     * Serialise a client. Intentionally flat — no campaign counts here;
     * those belong on a dedicated /clients/{id} endpoint if ever needed.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
