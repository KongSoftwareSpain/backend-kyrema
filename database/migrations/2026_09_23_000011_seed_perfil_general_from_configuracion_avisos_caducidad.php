<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Crea un perfil "General" sin ningún filtro marcado (aplica a todas las
     * sociedades/comerciales/socios/productos/formas de pago) a partir de la
     * configuración plana anterior, para no perder el comportamiento actual de
     * caducidad:enviar-avisos al desplegar el sistema de perfiles.
     */
    public function up(): void
    {
        $diasAviso = json_encode([30, 15, 1]);
        $activo = true;

        if (Schema::hasTable('configuracion_avisos_caducidad')) {
            $configActual = DB::table('configuracion_avisos_caducidad')->first();

            if ($configActual) {
                $diasAviso = $configActual->dias_aviso;
                $activo = (bool) $configActual->activo;
            }
        }

        DB::table('perfiles_envio_avisos')->insert([
            'nombre' => 'General',
            'activo' => $activo,
            'modo_tiempo' => 'dias_antes',
            'dias_aviso' => $diasAviso,
            'dia_envio_mensual' => null,
            'incluir_productos_activos' => true,
            'incluir_productos_renovados' => false,
            'fecha_actividad_socio_desde' => null,
            'evitar_duplicados' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('perfiles_envio_avisos')->where('nombre', 'General')->delete();
    }
};
