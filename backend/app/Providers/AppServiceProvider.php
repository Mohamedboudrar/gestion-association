<?php

namespace App\Providers;

use App\Policies\ActivityPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Spatie's Activity model lives outside App\Models, so Laravel's automatic
        // policy discovery (App\Models\X -> App\Policies\XPolicy) never finds
        // ActivityPolicy for it. Register it explicitly.
        Gate::policy(Activity::class, ActivityPolicy::class);
    }
}
