<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\DuplicateLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DuplicateLog>
 */
class DuplicateLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'user_id' => fake()->uuid(),
            'custom_fields' => ['first_name' => fake()->firstName()],
        ];
    }
}
