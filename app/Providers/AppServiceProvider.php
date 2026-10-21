<?php

namespace App\Providers;

use App\Listeners\BloquearEnvioCorreoSiDesactivado;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use App\Models\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
        Event::listen(MessageSending::class, BloquearEnvioCorreoSiDesactivado::class);

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
