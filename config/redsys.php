<?php

return [
    /**
     * Used to define the service URL. Possible values 'test', 'production' or 'local'.
     *
     * It's recommended to use 'local' during your development to enable a local gateway to test your
     * application without need to expose it.
     */
    'environment' => env('REDSYS_ENVIRONMENT'),

    /**
     * Values sent to Redsys.
     */
    'tpv' => [
        'terminal' => env('REDSYS_TERMINAL', 1),
        'currency' => \Creagia\Redsys\Enums\Currency::EUR,
        'merchantCode' => env('REDSYS_MERCHANT_CODE'), // Default test code: 999008881
        'key' => env('REDSYS_KEY'), // Default test key: sq7HjrUOBfKmC576ILgskD5srU870gJ7
    ],

    'notify_route_name' => 'redsys.notify',

    // Frontend bridge (donde aterriza el iframe tras pagar) — flujo antiguo, en desuso
    // tras pasar a redirección completa vía kyrema.org.
    'frontend' => [
        // p.ej. https://app.tu-dominio.com  (sin / al final)
        'base_url'    => env('FRONTEND_BASE_URL', 'http://localhost:4200'),
        // p.ej. /redsys/bridge
        'bridge_path' => env('REDSYS_BRIDGE_PATH', '/redsys/bridge'),
    ],

    // Página puente en kyrema.org que canjea el token y auto-envía el formulario a Redsys.
    'kyrema' => [
        // p.ej. https://kyrema.org (sin / al final)
        'base_url'          => env('REDSYS_KYREMA_BASE_URL', 'https://kyrema.org'),
        'pay_path'          => env('REDSYS_KYREMA_PAY_PATH', '/pago'),
        'landing_ok_path'   => env('REDSYS_KYREMA_LANDING_OK_PATH', '/pago/ok'),
        'landing_ko_path'   => env('REDSYS_KYREMA_LANDING_KO_PATH', '/pago/ko'),
    ],

    // Caducidad del token de un solo uso que consume el bridge de kyrema.org.
    'bridge_token_ttl_minutes' => env('REDSYS_BRIDGE_TOKEN_TTL_MINUTES', 15),

    // Si se rellena, solo estas IPs pueden canjear el token en /payments/redsys/form/{token}.
    // Vacío = sin restricción (útil mientras no se conoce la IP de salida de kyrema.org).
    'form_allowed_ips' => array_filter(array_map(
        'trim',
        explode(',', env('REDSYS_FORM_ALLOWED_IPS', ''))
    )),


    /**
     * Prefix used by the package routes. 'redsys' by default.
     */
    'routes_prefix' => env('REDSYS_ROUTE_PREFIX', 'redsys'),

    /**
     * Route names for successful and unsuccessful pages. Redsys redirects to these routes
     * after the payment is finished. By default, this package provides two neutral views.
     */
    'successful_payment_route_name'   => 'redsys.bridge.ok',
    'unsuccessful_payment_route_name' => 'redsys.bridge.ko',


    /**
     * Use an automatic prefix for the order number with the current year and month.
     */
    'order_num_auto_prefix' => true,

    /**
     * Redsys order number should be unique. Here you can set an order number prefix if you need it.
     * This prefix must be an integer number.
     */
    'order_num_prefix' => env('REDSYS_ORDER_NUM_PREFIX', 0),
];
