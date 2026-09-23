<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('perfiles_envio_avisos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->boolean('activo')->default(true);

            // 'dias_antes': dispara por días de antelación a fecha_de_fin (dias_aviso).
            // 'dia_mes': dispara una vez al mes (dia_envio_mensual) con todo lo que
            // caduque ese mes.
            $table->string('modo_tiempo')->default('dias_antes');
            $table->json('dias_aviso')->nullable();
            $table->unsignedTinyInteger('dia_envio_mensual')->nullable();

            $table->boolean('incluir_productos_activos')->default(true);
            $table->boolean('incluir_productos_renovados')->default(false);

            // No enviar a socios sin ningún producto contratado desde esta fecha.
            $table->date('fecha_actividad_socio_desde')->nullable();

            $table->boolean('evitar_duplicados')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('perfiles_envio_avisos');
    }
};
