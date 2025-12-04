<?php

return [

    'defaults' => [
        'guard' => 'comercial',
        'passwords' => 'socios',
    ],

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    */

    'guards' => [

        'web' => [
            'driver' => 'session',
            // lo apuntamos a comercial para evitar provider "users"
            'provider' => 'comercial',
        ],

        'comercial' => [
            'driver' => 'jwt',
            'provider' => 'comercial',
        ],

        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'comercial',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | AGREGAMOS provider users PERO VACÍO para que el framework no meta el suyo.
    |
    */

    'providers' => [
        // provider fantasma redefinido
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\Comercial::class, // cualquier modelo válido
        ],

        'comercial' => [
            'driver' => 'eloquent',
            'model' => App\Models\Comercial::class,
        ],

        'socios' => [
            'driver' => 'eloquent',
            'model' => App\Models\Socio::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Reset Brokers
    |--------------------------------------------------------------------------
    |
    | IGUAL: evitamos fallback añadiendo broker users vacío o alias correcto.
    |
    */

    'passwords' => [

        // redefinimos "users" para bloquear el del framework
        'users' => [
            'provider' => 'comercial', // o socios, como prefieras
            'table' => 'password_resets',
            'expire' => 60,
            'throttle' => 60,
        ],

        'comerciales' => [
            'provider' => 'comercial',
            'table' => 'password_resets',
            'expire' => 60,
            'throttle' => 60,
        ],

        'socios' => [
            'provider' => 'socios',
            'table' => 'password_resets',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
