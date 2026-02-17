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
            'chat.with_customers',
            'chat.with_agents',
            'chat.with_crew',
            'chat.with_internal',
            'walkie_talkie.access',
            'video_call.access',

            // Settings
            'settings.company',
            'settings.packages',
            'settings.allocations',
            'settings.payment_integrations',
            'settings.service_categories',
            'settings.budget_mappings',
            'settings.referral',
            'settings.contracts',
            'settings.lead_sources',
            'settings.workflows',

            // Roadmap
            'roadmap.view',
            'roadmap.edit',
            'roadmap.manage',

            // KPIs
            'kpis.view',
            'kpis.edit_targets',
            'kpis.enter_actuals',
            'kpis.manage',

            // Team management
            'team.manage_users',
            'team.manage_invitations',
            'team.manage_roles',

            // General admin
            'admin.access_settings',

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

            // Photos
            'photos.upload',
            'photos.delete',
            'photos.annotate',
            'photos.comment',

            // Referrals
            'referrals.view',
            'referrals.manage',

            // Employees
            'employees.view',
            'employees.create',
            'employees.edit',
            'employees.delete',
            'employees.view_sensitive',

            // Hiring / ATS
            'hiring.view',
            'hiring.manage',

            // Onboarding
            'onboarding.view',
            'onboarding.manage',

            // Time Tracking
            'time_tracking.view',
            'time_tracking.view_all',
            'time_tracking.manage',
            'time_tracking.clock',

            // Scheduling
            'scheduling.view',
            'scheduling.view_all',
            'scheduling.manage',

            // Payroll
            'payroll.view',
            'payroll.manage',

            // Media Hub
            'media.view',
            'media.record',
            'media.manage_channels',
            'media.manage_training',
            'media.send_emails',
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
            'chat.access', 'chat.with_customers', 'chat.with_agents', 'chat.with_crew', 'chat.with_internal',
            'walkie_talkie.access', 'video_call.access',
            // Settings
            'settings.company', 'settings.packages', 'settings.allocations',
            'settings.payment_integrations', 'settings.service_categories',
            'settings.budget_mappings', 'settings.referral', 'settings.contracts',
            'settings.lead_sources', 'settings.workflows',
            // Roadmap
            'roadmap.view', 'roadmap.edit', 'roadmap.manage',
            // KPIs
            'kpis.view', 'kpis.edit_targets', 'kpis.enter_actuals', 'kpis.manage',
            // Team
            'team.manage_users', 'team.manage_invitations',
            // Admin
            'admin.access_settings',
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
            // Photos
            'photos.upload', 'photos.delete', 'photos.annotate', 'photos.comment',
            // Referrals
            'referrals.view', 'referrals.manage',
            // Employees
            'employees.view', 'employees.create', 'employees.edit', 'employees.delete', 'employees.view_sensitive',
            // Hiring
            'hiring.view', 'hiring.manage',
            // Onboarding
            'onboarding.view', 'onboarding.manage',
            // Time Tracking
            'time_tracking.view', 'time_tracking.view_all', 'time_tracking.manage', 'time_tracking.clock',
            // Scheduling
            'scheduling.view', 'scheduling.view_all', 'scheduling.manage',
            // Payroll (view only)
            'payroll.view',
            // Media Hub
            'media.view', 'media.record', 'media.send_emails',
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
            'chat.access', 'chat.with_customers', 'chat.with_agents', 'chat.with_crew', 'chat.with_internal',
            'walkie_talkie.access', 'video_call.access',
            // Roadmap
            'roadmap.view', 'roadmap.edit',
            // KPIs
            'kpis.view', 'kpis.edit_targets', 'kpis.enter_actuals',
            // Team
            'team.manage_invitations',
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
            // Photos
            'photos.upload', 'photos.annotate', 'photos.comment',
            // Referrals
            'referrals.view',
            // Employees
            'employees.view', 'employees.create', 'employees.edit', 'employees.view_sensitive',
            // Hiring
            'hiring.view', 'hiring.manage',
            // Onboarding
            'onboarding.view', 'onboarding.manage',
            // Time Tracking
            'time_tracking.view', 'time_tracking.view_all', 'time_tracking.manage',
            // Scheduling
            'scheduling.view', 'scheduling.view_all', 'scheduling.manage',
            // Media Hub
            'media.view', 'media.record', 'media.send_emails',
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
            'chat.access', 'chat.with_customers', 'chat.with_agents', 'chat.with_internal',
            'walkie_talkie.access', 'video_call.access',
            // Roadmap
            'roadmap.view',
            // KPIs
            'kpis.view',
            // Business Plan (view only)
            'business_plan.view',
            // Invoices (view only)
            'invoices.view',
            // Photos
            'photos.upload', 'photos.comment',
            // Referrals
            'referrals.view',
        ]);

        // HR Manager - focused on employee management, hiring, onboarding, payroll
        $hrManager = Role::firstOrCreate(['name' => 'hr_manager', 'guard_name' => 'web']);
        $hrManager->syncPermissions([
            // People
            'people.view', 'people.create', 'people.edit',
            // Properties (view only)
            'properties.view',
            // Deals (view only)
            'deals.view', 'deals.create', 'deals.edit',
            // Jobs (view only)
            'jobs.view',
            // Planner
            'planner.view', 'planner.manage',
            // Communication
            'chat.access', 'chat.with_internal', 'chat.with_crew',
            // Settings (contracts only)
            'settings.contracts',
            // Employees (full access)
            'employees.view', 'employees.create', 'employees.edit', 'employees.delete', 'employees.view_sensitive',
            // Hiring
            'hiring.view', 'hiring.manage',
            // Onboarding
            'onboarding.view', 'onboarding.manage',
            // Time Tracking
            'time_tracking.view', 'time_tracking.view_all', 'time_tracking.manage', 'time_tracking.clock',
            // Scheduling
            'scheduling.view', 'scheduling.view_all', 'scheduling.manage',
            // Payroll
            'payroll.view', 'payroll.manage',
            // Team
            'team.manage_users', 'team.manage_invitations',
            // Photos
            'photos.upload', 'photos.comment',
        ]);

        // Crew Lead - minimal internal access
        $crewLead = Role::firstOrCreate(['name' => 'crew_lead', 'guard_name' => 'web']);
        $crewLead->syncPermissions([
            // Jobs (view only)
            'jobs.view',
            // Planner (own activities)
            'planner.view',
            // Communication
            'chat.access', 'chat.with_crew', 'chat.with_internal',
            'walkie_talkie.access', 'video_call.access',
            // Roadmap
            'roadmap.view',
            // Photos
            'photos.upload', 'photos.annotate', 'photos.comment',
        ]);
    }
}
