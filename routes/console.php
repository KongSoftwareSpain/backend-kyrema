<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Desactivado temporalmente por el usuario para ejecución manual en Azure
// Schedule::command('insurances:process-renewals')->daily();

// Archiva (caducado=true + registro en tabla 'caducados') los productos cuya
// fecha_de_fin ya ha pasado, sin importar si ya fueron renovados.
Schedule::command('insurances:limpiar-caducados')->daily();

// Envía avisos de caducidad a comerciales (30, 15 y 1 día antes)
Schedule::command('caducidad:enviar-avisos')->daily();

// Purga productos/anexos pendiente_pago cuyo token de pago con tarjeta caducó
// sin que Redsys llegara a notificar (cliente abandonó el pago).
Schedule::command('redsys:limpiar-pendientes')->hourly();
