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
        // Añadir columnas a la tabla campos
        if (Schema::hasTable('campos')) {
            Schema::table('campos', function (Blueprint $table) {
                if (!Schema::hasColumn('campos', 'salto_linea_x')) {
                    $table->string('salto_linea_x')->nullable();
                }
                if (!Schema::hasColumn('campos', 'salto_linea_y')) {
                    $table->string('salto_linea_y')->nullable();
                }
            });
        }

        // Añadir columnas a la tabla tipo_producto_polizas
        if (Schema::hasTable('tipo_producto_polizas')) {
            Schema::table('tipo_producto_polizas', function (Blueprint $table) {
                if (!Schema::hasColumn('tipo_producto_polizas', 'salto_linea_x')) {
                    $table->string('salto_linea_x')->nullable();
                }
                if (!Schema::hasColumn('tipo_producto_polizas', 'salto_linea_y')) {
                    $table->string('salto_linea_y')->nullable();
                }
            });
        }

        // Añadir columnas a la tabla campos_logos
        if (Schema::hasTable('campos_logos')) {
            Schema::table('campos_logos', function (Blueprint $table) {
                if (!Schema::hasColumn('campos_logos', 'salto_linea_x')) {
                    $table->string('salto_linea_x')->nullable();
                }
                if (!Schema::hasColumn('campos_logos', 'salto_linea_y')) {
                    $table->string('salto_linea_y')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('campos')) {
            Schema::table('campos', function (Blueprint $table) {
                $table->dropColumn(['salto_linea_x', 'salto_linea_y']);
            });
        }

        if (Schema::hasTable('tipo_producto_polizas')) {
            Schema::table('tipo_producto_polizas', function (Blueprint $table) {
                $table->dropColumn(['salto_linea_x', 'salto_linea_y']);
            });
        }

        if (Schema::hasTable('campos_logos')) {
            Schema::table('campos_logos', function (Blueprint $table) {
                $table->dropColumn(['salto_linea_x', 'salto_linea_y']);
            });
        }
    }
};
