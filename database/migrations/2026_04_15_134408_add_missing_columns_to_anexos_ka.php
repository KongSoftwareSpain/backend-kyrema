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
        Schema::table('anexos_ka', function (Blueprint $table) {
            if (!Schema::hasColumn('anexos_ka', 'codigo_producto')) $table->string('codigo_producto')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'comercial')) $table->string('comercial')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'numero_anexos')) $table->integer('numero_anexos')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'precio_final')) $table->decimal('precio_final', 8, 2)->nullable();
            if (!Schema::hasColumn('anexos_ka', 'sociedad')) $table->string('sociedad')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'tipo_de_pago')) $table->string('tipo_de_pago')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'apellido_1')) $table->string('apellido_1')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'apellido_2')) $table->string('apellido_2')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'codigo_postal')) $table->string('codigo_postal')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'dirección')) $table->string('dirección')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'dni')) $table->string('dni')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'email')) $table->string('email')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'fecha_de_nacimiento')) $table->dateTime('fecha_de_nacimiento')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'nombre_socio')) $table->string('nombre_socio')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'población')) $table->string('población')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'provincia')) $table->string('provincia')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'sexo')) $table->string('sexo')->nullable();
            if (!Schema::hasColumn('anexos_ka', 'telefono')) $table->string('telefono')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('anexos_ka', function (Blueprint $table) {
            $table->dropColumn([
                'codigo_producto', 'comercial', 'numero_anexos', 'precio_final', 'sociedad', 
                'tipo_de_pago', 'apellido_1', 'apellido_2', 'codigo_postal', 'dirección', 
                'dni', 'email', 'fecha_de_nacimiento', 'nombre_socio', 'población', 
                'provincia', 'sexo', 'telefono'
            ]);
        });
    }
};
