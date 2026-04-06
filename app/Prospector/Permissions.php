<?php

namespace App\Prospector;

class Permissions
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_SUPERVISOR = 'supervisor';

    public const ROLE_AGENT = 'agent';

    /**
     * All available permissions, grouped by feature area.
     *
     * @var array<string, array<string, string>>
     */
    public const PERMISSIONS = [
        'Dialer' => [
            'dialer.use' => 'Use the dialer',
            'dialer.bulk_dial' => 'Bulk dial campaigns',
            'dialer.disposition' => 'Disposition calls',
            'dialer.transfer' => 'Transfer calls',
        ],
        'Contacts' => [
            'contacts.view' => 'View contacts',
            'contacts.create' => 'Create contacts',
            'contacts.edit' => 'Edit contacts',
            'contacts.delete' => 'Delete contacts',
            'contacts.import' => 'Import contacts',
            'contacts.export' => 'Export contacts',
            'contacts.bulk_actions' => 'Bulk actions on contacts',
            'contacts.dnc' => 'Mark as Do Not Call',
        ],
        'Campaigns' => [
            'campaigns.view' => 'View campaigns',
            'campaigns.create' => 'Create campaigns',
            'campaigns.edit' => 'Edit campaigns',
            'campaigns.delete' => 'Delete campaigns',
            'campaigns.assign_contacts' => 'Add contacts to campaigns',
        ],
        'Sequences' => [
            'sequences.view' => 'View sequences',
            'sequences.create' => 'Create sequences',
            'sequences.edit' => 'Edit sequences',
            'sequences.delete' => 'Delete sequences',
            'sequences.enroll' => 'Enroll contacts in sequences',
        ],
        'Reports & Analytics' => [
            'reports.view' => 'View reports dashboard',
            'reports.create_charts' => 'Create custom charts',
            'reports.archive' => 'View contact archive',
            'reports.reengage' => 'Re-engage archived contacts',
            'reports.export' => 'Export report data',
        ],
        'Call Recordings' => [
            'recordings.listen' => 'Listen to call recordings',
            'recordings.download' => 'Download call recordings',
            'recordings.delete' => 'Delete call recordings',
            'recordings.intelligence' => 'View AI call intelligence',
        ],
        'Ringless Voicemail' => [
            'rvm.view' => 'View RVM templates',
            'rvm.create' => 'Create RVM templates',
            'rvm.delete' => 'Delete RVM templates',
            'rvm.send' => 'Send RVM drops',
        ],
        'SMS' => [
            'sms.view' => 'View SMS conversations',
            'sms.send' => 'Send SMS messages',
        ],
        'Settings' => [
            'settings.twilio' => 'Manage Twilio accounts',
            'settings.a2p' => 'Manage A2P compliance',
            'settings.ai' => 'Manage AI settings',
            'settings.automation' => 'Manage automation defaults',
            'settings.integrations' => 'Manage integrations / model bridge',
        ],
        'Companies' => [
            'companies.view' => 'View companies',
            'companies.create' => 'Create companies',
            'companies.edit' => 'Edit companies',
            'companies.delete' => 'Delete companies',
        ],
        'Agents & Permissions' => [
            'agents.view' => 'View agents',
            'agents.create' => 'Add new agents',
            'agents.edit' => 'Edit agent settings',
            'agents.delete' => 'Remove agents',
            'agents.manage_roles' => 'Assign roles and permissions',
        ],
    ];

    /**
     * Default permissions for each role.
     * '*' means all permissions are granted.
     *
     * @var array<string, array<int, string>|string>
     */
    public const ROLE_DEFAULTS = [
        self::ROLE_ADMIN => '*',
        self::ROLE_SUPERVISOR => [
            'dialer.use', 'dialer.bulk_dial', 'dialer.disposition', 'dialer.transfer',
            'contacts.view', 'contacts.create', 'contacts.edit', 'contacts.import', 'contacts.export', 'contacts.bulk_actions', 'contacts.dnc',
            'campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.assign_contacts',
            'sequences.view', 'sequences.create', 'sequences.edit', 'sequences.enroll',
            'reports.view', 'reports.create_charts', 'reports.archive', 'reports.reengage', 'reports.export',
            'recordings.listen', 'recordings.download', 'recordings.intelligence',
            'rvm.view', 'rvm.create', 'rvm.send',
            'sms.view', 'sms.send',
            'companies.view',
            'agents.view', 'agents.edit',
        ],
        self::ROLE_AGENT => [
            'dialer.use', 'dialer.disposition',
            'contacts.view', 'contacts.create', 'contacts.edit',
            'campaigns.view',
            'sequences.view', 'sequences.enroll',
            'reports.view',
            'recordings.listen', 'recordings.intelligence',
            'rvm.view', 'rvm.send',
            'sms.view', 'sms.send',
        ],
    ];

    /**
     * Get all permission keys as a flat array.
     *
     * @return array<int, string>
     */
    public static function allKeys(): array
    {
        $keys = [];
        foreach (self::PERMISSIONS as $group) {
            foreach (array_keys($group) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Get all role keys as an array.
     *
     * @return array<int, string>
     */
    public static function allRoles(): array
    {
        return [self::ROLE_ADMIN, self::ROLE_SUPERVISOR, self::ROLE_AGENT];
    }

    /**
     * Get the default permissions for a role.
     *
     * @return array<int, string>
     */
    public static function defaultsForRole(string $role): array
    {
        $defaults = self::ROLE_DEFAULTS[$role] ?? [];

        if ($defaults === '*') {
            return self::allKeys();
        }

        return $defaults;
    }

    /**
     * Get the human-readable label for a permission.
     */
    public static function label(string $permission): string
    {
        foreach (self::PERMISSIONS as $group) {
            if (isset($group[$permission])) {
                return $group[$permission];
            }
        }

        return $permission;
    }
}
