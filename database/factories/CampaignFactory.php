<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 month', 'now');

        return [
            'name' => fake()->sentence(3),
            'start_date' => $start,
            'end_date' => fake()->dateTimeBetween($start, '+1 month'),
        ];
    }

    public function forClient(int $clientId): static
    {
        return $this->state(fn () => ['client_id' => $clientId]);
    }
}
