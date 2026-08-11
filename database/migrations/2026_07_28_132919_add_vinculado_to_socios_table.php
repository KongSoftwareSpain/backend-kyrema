<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La tabla 'socios' no tiene create_table en este repo (se creó fuera de
     * Laravel) — se comprueba hasColumn en vez de asumir el estado de la tabla.
     */
    public function up(): void
    {
        if (Schema::hasTable('socios') && !Schema::hasColumn('socios', 'vinculado')) {
            Schema::table('socios', function (Blueprint $table) {
                $table->string('vinculado')->nullable()->after('codigo_postal');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('socios') && Schema::hasColumn('socios', 'vinculado')) {
            Schema::table('socios', function (Blueprint $table) {
                $table->dropColumn('vinculado');
            });
        }
    }
};
