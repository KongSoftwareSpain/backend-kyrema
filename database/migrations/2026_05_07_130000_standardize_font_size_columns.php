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
        Schema::table('campos', function (Blueprint $table) {
            // Aseguramos que font_size sea string para consistencia con otras tablas y evitar errores con strings vacíos
            if (Schema::hasColumn('campos', 'font_size')) {
                $table->string('font_size')->nullable()->change();
            } else {
                $table->string('font_size')->nullable();
            }
        });

        Schema::table('tipo_producto_polizas', function (Blueprint $table) {
            if (Schema::hasColumn('tipo_producto_polizas', 'font_size')) {
                $table->string('font_size')->nullable()->change();
            } else {
                $table->string('font_size')->nullable();
            }
        });
        
        Schema::table('campos_logos', function (Blueprint $table) {
            // Aunque los logos no suelen llevar font_size, lo añadimos por si acaso en el futuro o para evitar errores de mapeo
            if (!Schema::hasColumn('campos_logos', 'font_size')) {
                $table->string('font_size')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campos', function (Blueprint $table) {
            // No revertimos a int para evitar pérdida de datos si hay strings
        });
    }
};
