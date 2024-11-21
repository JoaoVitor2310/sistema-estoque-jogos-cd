<?php

namespace App\Providers;
use App\Models\AuthorizedUsers;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
        Gate::define('is-admin', function (User $user) {
            return $user->email === 'carcadeals@gmail.com';
        });

        Gate::define('can-edit', function (User $user) {
            $authorizedUser = AuthorizedUsers::where('email', $user->email)->first();

            return $authorizedUser && $authorizedUser->status === true;
        });
        // Vite::prefetch(concurrency: 3);
    }
}
