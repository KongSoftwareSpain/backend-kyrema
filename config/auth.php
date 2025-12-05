<?php

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'comercial' => [
            'driver' => 'jwt',
            'provider' => 'comercial',
        ],
    ],

    'providers' => [
        'comercial' => [
            'driver' => 'eloquent',
            'model' => App\Models\Comercial::class,
        ],
        'socios' => [
            'driver' => 'eloquent',
            'model' => App\Models\Socio::class,
        ],
    ],

    'passwords' => [
        'comerciales' => [
            'provider' => 'comercial', // orregido: ahora coincide con el provider
            'table' => 'password_resets', //  Corregido: nombre correcto de la tabla
            'expire' => 60,
            'throttle' => 60,
        ],
        'socios' => [
            'provider' => 'socios',
            'table' => 'password_resets', //  Corregido: nombre correcto de la tabla
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];