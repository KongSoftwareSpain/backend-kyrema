<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEstadoToTipoProductoTable extends Migration
{
    public function up(): void
    {
        Schema::table('tipo_producto', function (Blueprint $table) {
            $table->boolean('estado')->default(true)->after('nombre'); // ajusta la posición si es necesario
        });
    }

    public function down(): void
    {
        Schema::table('tipo_producto', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }
}
