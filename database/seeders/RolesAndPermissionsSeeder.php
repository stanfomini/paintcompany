<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // People
            'people.view',
            'people.create',
            'people.edit',
            'people.delete',

            // Properties
            'properties.view',
            'properties.create',
            'properties.edit',
            'properties.delete',

            // Deals
            'deals.view',
            'deals.create',
            'deals.edit',
            'deals.delete',

            // Estimates
            'estimates.view',
            'estimates.create',
            'estimates.edit',
            'estimates.delete',
            'estimates.send',

            // Financials (sensitive)
            'financials.view_cost_breakdown',
            'financials.view_painter_breakdown',
            'financials.manage_pricing',

            // Jobs
            'jobs.view',
            'jobs.edit',

            // Planner / Activities
            'planner.view',
            'planner.view_all',
            'planner.manage',

            // Agent Listings
            'agent_listings.view',
            'agent_listings.manage',

            // Production Rates
            'production_rates.view',
            'production_rates.manage',

            // Communication
            'chat.access',
            'walkie_talkie.access',
            'video_call.access',

            // Settings
            'settings.company',
            'settings.packages',
            'settings.allocations',
            'settings.payment_integrations',

            // Roadmap (legacy names - keep for backward compatibility)
            'view roadmap',
            'edit roadmap',
            'manage roadmap',

            // KPIs (legacy names)
            'view kpis',
            'edit kpi targets',
            'enter kpi actuals',
            'manage kpis',

            // Team management (legacy names)
            'manage users',
            'manage invitations',
            'manage roles',

            // General admin (legacy)
            'access admin settings',

            // Business Plan
            'business_plan.view',
            'business_plan.manage',

            // Invoices
            'invoices.view',
            'invoices.create',
            'invoices.send',
            'invoices.manage',

            // Payments
            'payments.view',
            'payments.record',

            // Vendors
            'vendors.view',
            'vendors.manage',

            // Accounting
            'accounting.view',
            'accounting.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create roles with permissions

        // Owner - full access to everything
        $owner = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $owner->syncPermissions(Permission::all());

        // Admin - everything except role management
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions([
            // People
            'people.view', 'people.create', 'people.edit', 'people.delete',
            // Properties
            'properties.view', 'properties.create', 'properties.edit', 'properties.delete',
            // Deals
            'deals.view', 'deals.create', 'deals.edit', 'deals.delete',
            // Estimates
            'estimates.view', 'estimates.create', 'estimates.edit', 'estimates.delete', 'estimates.send',
            // Financials
            'financials.view_cost_breakdown', 'financials.view_painter_breakdown', 'financials.manage_pricing',
            // Jobs
            'jobs.view', 'jobs.edit',
            // Planner
            'planner.view', 'planner.view_all', 'planner.manage',
            // Agent Listings
            'agent_listings.view', 'agent_listings.manage',
            // Production Rates
            'production_rates.view', 'production_rates.manage',
            // Communication
            'chat.access', 'walkie_talkie.access', 'video_call.access',
            // Settings
            'settings.company', 'settings.packages', 'settings.allocations',
            // Roadmap
            'view roadmap', 'edit roadmap', 'manage roadmap',
            // KPIs
            'view kpis', 'edit kpi targets', 'enter kpi actuals', 'manage kpis',
            // Team
            'manage users', 'manage invitations',
            // Admin
            'access admin settings',
            // Business Plan
            'business_plan.view', 'business_plan.manage',
            // Invoices
            'invoices.view', 'invoices.create', 'invoices.send', 'invoices.manage',
            // Payments
            'payments.view', 'payments.record',
            // Vendors
            'vendors.view', 'vendors.manage',
            // Accounting
            'accounting.view', 'accounting.manage',
        ]);

        // Manager - view + edit, no management or deletion
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->syncPermissions([
            // People
            'people.view', 'people.create', 'people.edit',
            // Properties
            'properties.view', 'properties.create', 'properties.edit',
            // Deals
            'deals.view', 'deals.create', 'deals.edit',
            // Estimates
            'estimates.view', 'estimates.create', 'estimates.edit', 'estimates.send',
            // Financials (view only)
            'financials.view_cost_breakdown', 'financials.view_painter_breakdown',
            // Jobs
            'jobs.view', 'jobs.edit',
            // Planner
            'planner.view', 'planner.view_all', 'planner.manage',
            // Agent Listings (view only)
            'agent_listings.view',
            // Production Rates (view only)
            'production_rates.view',
            // Communication
            'chat.access', 'walkie_talkie.access', 'video_call.access',
            // Roadmap
            'view roadmap', 'edit roadmap',
            // KPIs
            'view kpis', 'edit kpi targets', 'enter kpi actuals',
            // Team
            'manage invitations',
            // Business Plan
            'business_plan.view', 'business_plan.manage',
            // Invoices
            'invoices.view', 'invoices.create', 'invoices.send',
            // Payments
            'payments.view', 'payments.record',
            // Vendors
            'vendors.view', 'vendors.manage',
            // Accounting
            'accounting.view',
        ]);

        // Estimator - focused on estimates, properties, deals
        $estimator = Role::firstOrCreate(['name' => 'estimator', 'guard_name' => 'web']);
        $estimator->syncPermissions([
            // People (view only)
            'people.view',
            // Properties
            'properties.view', 'properties.create', 'properties.edit',
            // Deals
            'deals.view', 'deals.create', 'deals.edit',
            // Estimates (all except delete)
            'estimates.view', 'estimates.create', 'estimates.edit', 'estimates.send',
            // Jobs (view only)
            'jobs.view',
            // Planner (own activities)
            'planner.view',
            // Production Rates (view only)
            'production_rates.view',
            // Communication
            'chat.access', 'walkie_talkie.access', 'video_call.access',
            // Roadmap
            'view roadmap',
            // KPIs
            'view kpis',
            // Business Plan (view only)
            'business_plan.view',
            // Invoices (view only)
            'invoices.view',
        ]);

        // Crew Lead - minimal internal access
        $crewLead = Role::firstOrCreate(['name' => 'crew_lead', 'guard_name' => 'web']);
        $crewLead->syncPermissions([
            // Jobs (view only)
            'jobs.view',
            // Planner (own activities)
            'planner.view',
            // Communication
            'chat.access', 'walkie_talkie.access', 'video_call.access',
            // Roadmap
            'view roadmap',
        ]);
    }
}
