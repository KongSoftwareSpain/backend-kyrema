<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tablas = DB::table('tipo_producto')
            ->whereNotNull('letras_identificacion')
            ->pluck('letras_identificacion')
            ->map(fn ($letras) => strtolower($letras))
            ->unique();

        foreach ($tablas as $tabla) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'fecha_de_fin') && !Schema::hasColumn($tabla, 'caducado')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->boolean('caducado')->default(false);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tablas = DB::table('tipo_producto')
            ->whereNotNull('letras_identificacion')
            ->pluck('letras_identificacion')
            ->map(fn ($letras) => strtolower($letras))
            ->unique();

        foreach ($tablas as $tabla) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'caducado')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropColumn('caducado');
                });
            }
        }
    }
};
