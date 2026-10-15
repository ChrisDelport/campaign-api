<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignData;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignData>
 */
class CampaignDataFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'user_id' => fake()->uuid(),
            'video_url' => fake()->url(),
            'custom_fields' => ['first_name' => fake()->firstName()],
        ];
    }
}
