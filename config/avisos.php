<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aviso de caducidad de productos
    |--------------------------------------------------------------------------
    |
    | Días de antelación con los que se avisa al comercial responsable antes
    | de que un producto caduque (fecha_de_fin). Cambiar solo esta variable
    | de entorno no requiere tocar código.
    |
    */

    'caducidad_dias' => env('AVISO_CADUCIDAD_DIAS', 15),

];
