<?php

namespace App\Providers;

use App\Uitrusting\Classifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Parses config/uitrusting.php once per process.
        $this->app->singleton(Classifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
