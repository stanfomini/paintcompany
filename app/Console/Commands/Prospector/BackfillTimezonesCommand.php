<?php

namespace App\Console\Commands\Prospector;

use App\Models\Prospector\Contact;
use Illuminate\Console\Command;

class BackfillTimezonesCommand extends Command
{
    protected $signature = 'prospector:backfill-timezones';

    protected $description = 'Backfill timezone from area code for contacts missing timezone';

    public function handle(): int
    {
        $timezones = config('prospector-timezones');

        if (empty($timezones)) {
            $this->error('No timezone mappings found in config/prospector-timezones.php');

            return self::FAILURE;
        }

        $contacts = Contact::whereNull('timezone')
            ->whereNotNull('phone')
            ->get();

        if ($contacts->isEmpty()) {
            $this->info('All contacts already have timezones assigned.');

            return self::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($contacts as $contact) {
            $areaCode = $contact->getAreaCode();
            $tz = $timezones[$areaCode] ?? null;

            if ($tz) {
                $contact->update(['timezone' => $tz]);
                $updated++;
            } else {
                $skipped++;
            }
        }

        $this->info("Backfilled {$updated} contacts. Skipped {$skipped} (no mapping for area code).");

        return self::SUCCESS;
    }
}
