<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SalesTerritory>
 */
class SalesTerritoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $centerLat = fake()->latitude(30, 45);
        $centerLng = fake()->longitude(-120, -75);

        return [
            'name' => fake()->randomElement([
                'Downtown', 'Northside', 'Southside', 'Eastside', 'Westside',
                'Midtown', 'Uptown', 'Lakeside', 'Riverside', 'Hillcrest',
                'Oak Park', 'Maple Heights', 'Cedar Ridge', 'Pine Valley', 'Elm Street',
            ]).' '.fake()->randomElement(['Zone', 'Territory', 'District', 'Area']),
            'description' => fake()->optional(0.5)->sentence(),
            'color' => fake()->hexColor(),
            'boundary' => $this->generatePolygon($centerLat, $centerLng),
            'status' => 'active',
            'center_lat' => $centerLat,
            'center_lng' => $centerLng,
        ];
    }

    /**
     * Generate a realistic GeoJSON polygon around a center point.
     *
     * @return array{type: string, coordinates: array}
     */
    private function generatePolygon(float $centerLat, float $centerLng): array
    {
        $points = [];
        $numPoints = 6;
        $radius = 0.01; // roughly 1km

        for ($i = 0; $i < $numPoints; $i++) {
            $angle = (2 * M_PI * $i) / $numPoints;
            $jitter = fake()->randomFloat(4, 0.7, 1.3);
            $lng = $centerLng + ($radius * cos($angle) * $jitter);
            $lat = $centerLat + ($radius * sin($angle) * $jitter);
            $points[] = [round($lng, 7), round($lat, 7)];
        }

        // Close the polygon
        $points[] = $points[0];

        return [
            'type' => 'Polygon',
            'coordinates' => [$points],
        ];
    }

    /**
     * Set the territory as active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Set the territory as archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'archived',
        ]);
    }

    /**
     * Assign the territory to a user.
     */
    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_user_id' => $user->id,
        ]);
    }

    /**
     * Set a specific center location.
     */
    public function atLocation(float $lat, float $lng): static
    {
        return $this->state(fn (array $attributes) => [
            'center_lat' => $lat,
            'center_lng' => $lng,
            'boundary' => $this->generatePolygon($lat, $lng),
        ]);
    }
}
