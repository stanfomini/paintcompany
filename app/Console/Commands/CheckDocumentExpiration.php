<?php

namespace App\Console\Commands;

use App\Models\EmployeeDocument;
use App\Models\User;
use App\Notifications\DocumentExpiring;
use Illuminate\Console\Command;

class CheckDocumentExpiration extends Command
{
    protected $signature = 'documents:check-expiration';

    protected $description = 'Check for employee documents expiring within 30 days and notify employees and admins';

    public function handle(): int
    {
        $documents = EmployeeDocument::query()
            ->expiringSoon()
            ->where('status', 'approved')
            ->with(['employeeProfile.user'])
            ->get();

        if ($documents->isEmpty()) {
            $this->info('No documents expiring soon.');

            return self::SUCCESS;
        }

        $admins = User::query()
            ->where('type', 'internal')
            ->where('is_active', true)
            ->permission('employees.edit')
            ->get();

        $notifiedCount = 0;

        foreach ($documents as $document) {
            $employee = $document->employeeProfile->user ?? null;

            if ($employee) {
                $employee->notify(new DocumentExpiring($document));
                $notifiedCount++;
            }

            foreach ($admins as $admin) {
                $admin->notify(new DocumentExpiring($document));
            }
        }

        $this->info("Notified about {$documents->count()} expiring document(s) ({$notifiedCount} employee(s), {$admins->count()} admin(s)).");

        return self::SUCCESS;
    }
}
