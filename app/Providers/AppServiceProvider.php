<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use App\Models\Posyandu;
use App\Models\School;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\URL;

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

        //untuk ngrok
        
        if (str_contains(config('app.url'), 'ngrok-free.app')) {
            URL::forceScheme('https');
        }

        Relation::morphMap([
            'school' => School::class,
            'posyandu' => Posyandu::class,
        ]);

        Gate::before(
            function (
                User $user,
                string $ability
            ): ?bool {
                return $user->is_super_admin
                    ? true
                    : null;
            }
        );
    }
}
