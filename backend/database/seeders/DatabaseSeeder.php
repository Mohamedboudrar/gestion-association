<?php

namespace Database\Seeders;

use Database\Seeders\Demo\DemoActivityNotificationsSeeder;
use Database\Seeders\Demo\DemoAssociationSeeder;
use Database\Seeders\Demo\DemoDonationsSeeder;
use Database\Seeders\Demo\DemoDuesSubscriptionsSeeder;
use Database\Seeders\Demo\DemoExpensesSeeder;
use Database\Seeders\Demo\DemoProjectFinalizeSeeder;
use Database\Seeders\Demo\DemoProjectsSeeder;
use Database\Seeders\Demo\DemoUsersMembersSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Core setup — roles/permissions must exist before any user is
        // assigned one, and the president must exist before Demo seeders
        // reuse it as the default bureau causer.
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            PresidentSeeder::class,
        ]);

        // Realistic demo/stress-test data. Split by domain and ordered by
        // dependency: association settings before dues (annual amount),
        // members before dues/committees, dues/subscriptions before
        // committees (verified-subscription eligibility), projects+committees
        // before donations/expenses (which need a project + a committee to
        // attribute them to), and finalize (reports + dissolution) only once
        // those totals exist to summarize. Notifications/activity volume is
        // a byproduct of the domain seeders above; the last seeder only adds
        // what nothing else produces (login/logout, read/unread mix).
        $this->call([
            DemoAssociationSeeder::class,
            DemoUsersMembersSeeder::class,
            DemoDuesSubscriptionsSeeder::class,
            DemoProjectsSeeder::class,
            DemoDonationsSeeder::class,
            DemoExpensesSeeder::class,
            DemoProjectFinalizeSeeder::class,
            DemoActivityNotificationsSeeder::class,
        ]);
    }
}

