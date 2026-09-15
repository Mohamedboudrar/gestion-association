<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every Feature/Unit test gets Tests\TestCase + a fresh sqlite :memory:
| schema per test (RefreshDatabase). Roles/permissions are seeded once per
| test via the beforeEach() below, since almost every test needs the real
| Spatie roles/permissions to exist — see tests/Support/helpers.php for the
| reusable actor/committee-setup functions built on top of this.
|
*/

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function () {
        // The 'array' cache driver (see phpunit.xml) lives for the whole
        // test process, not just one test — RefreshDatabase resets the DB
        // per test but never touches the cache. Without this, a cached
        // dashboard summary (see DashboardController) computed by one
        // test's seeded data could leak into a later test asserting
        // different, exact figures against its own fresh data.
        \Illuminate\Support\Facades\Cache::flush();

        $this->seed([
            \Database\Seeders\RoleSeeder::class,
            \Database\Seeders\PermissionSeeder::class,
            \Database\Seeders\RolePermissionSeeder::class,
        ]);
    })
    ->in('Feature', 'Unit');

require __DIR__.'/Support/helpers.php';
