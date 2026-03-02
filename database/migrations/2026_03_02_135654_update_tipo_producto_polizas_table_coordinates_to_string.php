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
        Schema::table('tipo_producto_polizas', function (Blueprint $table) {
            $table->string('fila')->nullable()->change();
            $table->string('columna')->nullable()->change();

            if (!Schema::hasColumn('tipo_producto_polizas', 'fila_logo')) {
                $table->string('fila_logo')->nullable();
            }
            if (!Schema::hasColumn('tipo_producto_polizas', 'columna_logo')) {
                $table->string('columna_logo')->nullable();
            }
            if (!Schema::hasColumn('tipo_producto_polizas', 'page_logo')) {
                $table->string('page_logo')->nullable();
            }
            if (!Schema::hasColumn('tipo_producto_polizas', 'font_size')) {
                $table->string('font_size')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tipo_producto_polizas', function (Blueprint $table) {
            // No easy way to rollback to original types without knowing them for sure, 
            // but we can at least clarify they were likely integers if we wanted to.
            // Leaving empty as string is more flexible.
        });
    }
};
