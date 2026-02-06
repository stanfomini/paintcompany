<?php

namespace App\Services;

use App\Models\RoadmapCategory;
use App\Models\RoadmapItem;
use App\Models\RoadmapPhase;

class RoadmapSeederService
{
    /**
     * Seed the default roadmap structure based on the Master Business Plan spreadsheet.
     */
    public function seedDefaultRoadmap(): void
    {
        $this->seedFundamentals();
        $this->seedPhaseOneFoundation();
        $this->seedPhaseTwoTeamBuilding();
        $this->seedPhaseThreeSharedFuture();
    }

    protected function seedFundamentals(): void
    {
        $phase = RoadmapPhase::create([
            'name' => 'Fundamentals',
            'slug' => 'fundamentals',
            'description' => 'Core business foundation elements that must be in place before scaling.',
            'sort_order' => 0,
            'is_active' => true,
            'status' => RoadmapPhase::STATUS_NOT_STARTED,
        ]);

        $this->seedCategory($phase, 'Core Business', 'core-business', [
            'Master Business Plan & Roadmap Built',
            'Living an Aligned Life - Life Commitments',
            'Consistent Time Dedicated to Learning',
        ]);
    }

    protected function seedPhaseOneFoundation(): void
    {
        $phase = RoadmapPhase::create([
            'name' => 'Phase 1: The Foundation',
            'slug' => 'foundation',
            'description' => 'Building the operational foundation for a profitable painting business.',
            'sort_order' => 1,
            'is_active' => true,
            'status' => RoadmapPhase::STATUS_NOT_STARTED,
        ]);

        $this->seedCategory($phase, 'Marketing', 'marketing', [
            '8-10 Estimates Per Week',
            '10% or Less Cost of Marketing',
        ]);

        $this->seedCategory($phase, 'Estimating', 'estimating', [
            'Set Estimating Procedure',
            '50% Gross Profit Margin (Labor & Materials)',
        ]);

        $this->seedCategory($phase, 'Sales', 'sales', [
            '35% of Estimates',
            '$1,000/Estimate',
            'Selling Jobs @ 50% Profit Margin',
        ]);

        $this->seedCategory($phase, 'Production', 'production', [
            'Produce What We Sell ($10k in sales = $10k in production)',
            '50% Gross Profit Margin (Labor & Materials)',
            'Our Subcontractors Are Happy With Our Prices',
        ]);

        $this->seedCategory($phase, 'Profit', 'profit', [
            '50% Gross Profit (Labor & Materials)',
            'Maximum 10% Marketing',
            'Maximum 10% Overhead',
            'Minimum 30% Net Profit',
        ]);

        $this->seedCategory($phase, 'Admin', 'admin', [
            'Consistent Updates on the MBP File',
            'Tech Stack for Tracking Business Activity in Place',
        ]);

        $this->seedCategory($phase, 'Time / Efficiency', 'time-efficiency', [
            '40 Hours Per Week Or Less',
        ]);

        $this->seedCategory($phase, 'Add Commercial Painting', 'commercial-painting', [
            'Completed PBP Commercial Painting Course',
        ]);
    }

    protected function seedPhaseTwoTeamBuilding(): void
    {
        $phase = RoadmapPhase::create([
            'name' => 'Phase 2: Team Building',
            'slug' => 'team-building',
            'description' => 'Hiring and developing key team members to scale the business.',
            'sort_order' => 2,
            'is_active' => true,
            'status' => RoadmapPhase::STATUS_NOT_STARTED,
        ]);

        // Project Manager
        $this->seedCategory($phase, 'Project Manager', 'project-manager', [
            'Pre-Hire Checklist Completed',
            'Hired',
            'Onboarding Checklist Completed',
        ]);

        $this->seedCategory($phase, 'Production Manager Producing Results', 'production-manager-results', [
            'Revenue Produced: $30k',
            'Gross Profit: 50%',
            'Customer Satisfaction: 9/10',
            'Subcontractor Retention: Reliable Crews',
            'Life Balance',
        ]);

        // Sales Rep
        $this->seedCategory($phase, 'Sales Rep', 'sales-rep', [
            'Pre-Hire Checklist Completed',
            'Hired',
            'Onboarding Checklist Completed',
        ]);

        $this->seedCategory($phase, 'Sales Rep Producing Results', 'sales-rep-results', [
            'Estimates Per Week: 14',
            'Sales Rate: 30%',
            'Estimating Accuracy',
            'Life Balance',
        ]);

        // Office Manager
        $this->seedCategory($phase, 'Office Manager', 'office-manager', [
            'Pre-Hire Checklist Completed',
            'Hired',
            'Onboarding Checklist Completed',
        ]);

        $this->seedCategory($phase, 'Office Manager Producing Results', 'office-manager-results', [
            'CRM Organization',
            'Lead Conversion',
            'Estimate Quality',
            'Responsiveness',
        ]);
    }

    protected function seedPhaseThreeSharedFuture(): void
    {
        $phase = RoadmapPhase::create([
            'name' => 'Phase 3: Create a Shared Future',
            'slug' => 'shared-future',
            'description' => 'Building a shared vision and long-term success for the team.',
            'sort_order' => 3,
            'is_active' => false, // Coming soon
            'status' => RoadmapPhase::STATUS_NOT_STARTED,
        ]);

        $this->seedCategory($phase, 'Coming Soon', 'coming-soon', [
            'Content to be added',
        ]);
    }

    protected function seedCategory(RoadmapPhase $phase, string $name, string $slug, array $items): RoadmapCategory
    {
        $category = RoadmapCategory::create([
            'roadmap_phase_id' => $phase->id,
            'name' => $name,
            'slug' => $slug,
            'sort_order' => $phase->categories()->count(),
        ]);

        foreach ($items as $index => $title) {
            RoadmapItem::create([
                'roadmap_category_id' => $category->id,
                'title' => $title,
                'status' => RoadmapItem::STATUS_NOT_STARTED,
                'sort_order' => $index,
            ]);
        }

        return $category;
    }

    /**
     * Clear all roadmap data (useful for reseeding).
     */
    public function clearAll(): void
    {
        RoadmapItem::query()->delete();
        RoadmapCategory::query()->delete();
        RoadmapPhase::query()->delete();
    }
}
