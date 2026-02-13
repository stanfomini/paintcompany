<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\RoiCalculation;
use App\Models\User;

/**
 * Rough ROI calculator for real estate agents as a lead magnet.
 *
 * Provides ballpark painting cost estimates and property value increase
 * projections based on industry data. NOT a real PBP estimate — intended
 * for agent-facing marketing materials and deal qualification.
 */
class PropertyValueCalculatorService
{
    /**
     * Value increase percentages for exterior painting (industry data).
     * Exterior paint is one of the highest-ROI home improvements for resale.
     */
    private const EXTERIOR_VALUE_INCREASE_LOW = 0.02;

    private const EXTERIOR_VALUE_INCREASE_HIGH = 0.05;

    /**
     * Interior painting ROI multiplier (industry data).
     * Interior paint typically recoups 107% of cost (cost + 7% gain).
     */
    private const INTERIOR_ROI_MULTIPLIER = 1.07;

    /**
     * Blended weight when scope is 'both'.
     * Interior contributes more rooms so gets higher weight.
     */
    private const INTERIOR_WEIGHT = 0.60;

    private const EXTERIOR_WEIGHT = 0.40;

    /**
     * Cost estimate range multipliers around the midpoint.
     */
    private const RANGE_LOW_MULTIPLIER = 0.85;

    private const RANGE_HIGH_MULTIPLIER = 1.15;

    /**
     * Main entry point. Calculates rough ROI for a property.
     *
     * @param  string  $scope  'interior', 'exterior', or 'both'
     * @param  string  $intent  'selling' or 'buying'
     * @return array{cost_low: float, cost_high: float, value_increase_low: float, value_increase_high: float, roi_percent_low: float, roi_percent_high: float, inputs_snapshot: array}
     */
    public function calculate(float $propertyValue, int $sqft, int $bedrooms, string $scope, string $intent): array
    {
        $costEstimate = $this->estimatePaintingCost($sqft, $bedrooms, $scope);
        $valueIncrease = $this->estimateValueIncrease(
            $propertyValue,
            $costEstimate['low'],
            $costEstimate['high'],
            $scope
        );
        $roi = $this->calculateRoi(
            $costEstimate['low'],
            $costEstimate['high'],
            $valueIncrease['low'],
            $valueIncrease['high']
        );

        $settings = CompanySetting::getInstance();

        return [
            'cost_low' => round($costEstimate['low'], 2),
            'cost_high' => round($costEstimate['high'], 2),
            'value_increase_low' => round($valueIncrease['low'], 2),
            'value_increase_high' => round($valueIncrease['high'], 2),
            'roi_percent_low' => round($roi['low'], 2),
            'roi_percent_high' => round($roi['high'], 2),
            'inputs_snapshot' => [
                'property_value' => $propertyValue,
                'sqft' => $sqft,
                'bedrooms' => $bedrooms,
                'scope' => $scope,
                'intent' => $intent,
                'interior_sqft_rate' => (float) $settings->default_interior_sqft_rate,
                'exterior_sqft_rate' => (float) $settings->default_exterior_sqft_rate,
            ],
        ];
    }

    /**
     * Estimate rough painting cost from sqft and bedroom count.
     *
     * Uses a room count heuristic: bedrooms + common areas (ceil(bedrooms * 1.5)).
     * Then multiplies total sqft by scope-specific $/sqft rates from CompanySetting.
     *
     * @param  string  $scope  'interior', 'exterior', or 'both'
     * @return array{midpoint: float, low: float, high: float}
     */
    public function estimatePaintingCost(int $sqft, int $bedrooms, string $scope): array
    {
        $settings = CompanySetting::getInstance();

        if ($scope === 'both') {
            $interior = $this->estimatePaintingCost($sqft, $bedrooms, 'interior');
            $exterior = $this->estimatePaintingCost($sqft, $bedrooms, 'exterior');

            $midpoint = $interior['midpoint'] + $exterior['midpoint'];

            return [
                'midpoint' => round($midpoint, 2),
                'low' => round($midpoint * self::RANGE_LOW_MULTIPLIER, 2),
                'high' => round($midpoint * self::RANGE_HIGH_MULTIPLIER, 2),
            ];
        }

        $rate = $scope === 'exterior'
            ? (float) $settings->default_exterior_sqft_rate
            : (float) $settings->default_interior_sqft_rate;

        // Room count heuristic: bedrooms + common areas
        $commonAreas = (int) ceil($bedrooms * 1.5);
        $roomCount = $bedrooms + $commonAreas;

        // Average room sqft = total property sqft / room count
        $avgRoomSqft = $sqft / max($roomCount, 1);

        // For interior: price is based on paintable wall area (rooms × avg sqft × rate)
        // For exterior: price is based on total exterior sqft (simpler)
        if ($scope === 'interior') {
            $midpoint = $roomCount * $avgRoomSqft * $rate;
        } else {
            $midpoint = $sqft * $rate;
        }

        return [
            'midpoint' => round($midpoint, 2),
            'low' => round($midpoint * self::RANGE_LOW_MULTIPLIER, 2),
            'high' => round($midpoint * self::RANGE_HIGH_MULTIPLIER, 2),
        ];
    }

    /**
     * Estimate how much the property value increases from painting.
     *
     * - Exterior: 2-5% of home value (industry data for curb appeal).
     * - Interior: ~107% ROI (cost recouped + 7% gain).
     * - Both: weighted blend (60% interior / 40% exterior).
     *
     * @return array{low: float, high: float}
     */
    public function estimateValueIncrease(float $propertyValue, float $costLow, float $costHigh, string $scope): array
    {
        if ($scope === 'exterior') {
            return [
                'low' => round($propertyValue * self::EXTERIOR_VALUE_INCREASE_LOW, 2),
                'high' => round($propertyValue * self::EXTERIOR_VALUE_INCREASE_HIGH, 2),
            ];
        }

        if ($scope === 'interior') {
            return [
                'low' => round($costLow * self::INTERIOR_ROI_MULTIPLIER, 2),
                'high' => round($costHigh * self::INTERIOR_ROI_MULTIPLIER, 2),
            ];
        }

        // Scope = 'both': weighted blend
        $exteriorLow = $propertyValue * self::EXTERIOR_VALUE_INCREASE_LOW;
        $exteriorHigh = $propertyValue * self::EXTERIOR_VALUE_INCREASE_HIGH;
        $interiorLow = $costLow * self::INTERIOR_ROI_MULTIPLIER;
        $interiorHigh = $costHigh * self::INTERIOR_ROI_MULTIPLIER;

        return [
            'low' => round(
                ($interiorLow * self::INTERIOR_WEIGHT) + ($exteriorLow * self::EXTERIOR_WEIGHT),
                2
            ),
            'high' => round(
                ($interiorHigh * self::INTERIOR_WEIGHT) + ($exteriorHigh * self::EXTERIOR_WEIGHT),
                2
            ),
        ];
    }

    /**
     * Calculate ROI percentages from cost and value increase ranges.
     *
     * ROI % = (value increase / cost) * 100
     *
     * Low ROI uses the conservative estimate (low value increase / high cost).
     * High ROI uses the optimistic estimate (high value increase / low cost).
     *
     * @return array{low: float, high: float}
     */
    public function calculateRoi(float $costLow, float $costHigh, float $valueIncreaseLow, float $valueIncreaseHigh): array
    {
        // Conservative: low value gain / high cost
        $roiLow = $costHigh > 0
            ? ($valueIncreaseLow / $costHigh) * 100
            : 0;

        // Optimistic: high value gain / low cost
        $roiHigh = $costLow > 0
            ? ($valueIncreaseHigh / $costLow) * 100
            : 0;

        return [
            'low' => round($roiLow, 2),
            'high' => round($roiHigh, 2),
        ];
    }

    /**
     * Persist a calculation result to the database.
     *
     * Automatically generates a public sharing token valid for 30 days.
     */
    public function saveCalculation(Deal $deal, User $agent, array $results): RoiCalculation
    {
        $calculation = RoiCalculation::create([
            'deal_id' => $deal->id,
            'agent_id' => $agent->id,
            'property_value' => $results['inputs_snapshot']['property_value'],
            'estimated_sqft' => $results['inputs_snapshot']['sqft'],
            'bedroom_count' => $results['inputs_snapshot']['bedrooms'],
            'scope' => $results['inputs_snapshot']['scope'],
            'intent' => $results['inputs_snapshot']['intent'],
            'estimated_cost_low' => $results['cost_low'],
            'estimated_cost_high' => $results['cost_high'],
            'estimated_value_increase_low' => $results['value_increase_low'],
            'estimated_value_increase_high' => $results['value_increase_high'],
            'roi_percent_low' => $results['roi_percent_low'],
            'roi_percent_high' => $results['roi_percent_high'],
            'calculation_inputs' => $results['inputs_snapshot'],
        ]);

        $calculation->generatePublicToken();

        return $calculation;
    }
}
