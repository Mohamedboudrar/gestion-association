<?php

namespace Database\Seeders\Demo;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// Adds the login/logout activity entries the rest of the seeder run never
// produces on its own (those aren't Eloquent model events, so the
// LogsActivity trait never logs them), and finishes with a realistic
// read/unread pass over every notification created by the domain seeders
// above (each of which already fired real Notification::notifyUsers/
// notifyRoles calls — nothing here invents a notification type).
class DemoActivityNotificationsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedLoginLogoutActivity();
        $this->markNotificationsReadUnread();

        $totalActivities = DB::table('activity_log')->count();
        $totalNotifications = Notification::count();

        $this->command?->info("{$totalActivities} activity log entries, {$totalNotifications} notifications now in the database.");
    }

    private function seedLoginLogoutActivity(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            $sessions = random_int(1, 4);

            for ($i = 0; $i < $sessions; $i++) {
                $loginAt = now()->subDays(random_int(0, 90))->subMinutes(random_int(0, 1440));
                $logoutAt = (clone $loginAt)->addMinutes(random_int(5, 240));

                $login = activity()
                    ->causedBy($user)
                    ->withProperties(['ip' => $this->fakeIp()])
                    ->log('User logged in.');
                $login->created_at = $loginAt;
                $login->save();

                $logout = activity()
                    ->causedBy($user)
                    ->log('User logged out.');
                $logout->created_at = $logoutAt;
                $logout->save();
            }
        }
    }

    private function markNotificationsReadUnread(): void
    {
        $total = Notification::count();
        $readCount = (int) round($total * 0.6);

        if ($readCount === 0) {
            return;
        }

        DB::table('notifications')
            ->inRandomOrder()
            ->limit($readCount)
            ->update(['read_at' => now()->subDays(random_int(0, 30))]);
    }

    private function fakeIp(): string
    {
        return implode('.', [
            random_int(41, 197), random_int(0, 255), random_int(0, 255), random_int(1, 254),
        ]);
    }
}
