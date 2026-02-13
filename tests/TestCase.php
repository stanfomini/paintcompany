<?php

namespace Tests;

use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Safety guard: never allow tests to run against MySQL.
        // Tests must use SQLite in-memory (configured in phpunit.xml).
        // This prevents accidental data loss if phpunit.xml env vars aren't loaded.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->fail(
                'Tests are running against '.DB::connection()->getDriverName().' instead of SQLite. '
                .'This would destroy your development database. '
                .'Ensure phpunit.xml sets DB_CONNECTION=sqlite and DB_DATABASE=:memory:.'
            );
        }

        CompanySetting::clearInstanceCache();
    }
}
