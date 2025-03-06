<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('comercial_comision', 'comisiones_comercial');

        Schema::table('comisiones_comercial', function (Blueprint $table) {
            // Agregar nuevas columnas
            $table->unsignedBigInteger('tipo_producto_id')->after('id_comercial');
            $table->string('tipo', 10)->after('tipo_producto_id'); // 'fija' o 'porcentual'
            $table->renameColumn('comision', 'valor');

            // Ajustar columna 'porcentual' a 'tipo' (cambiando booleano a string)
            $table->dropColumn('porcentual');

            // Agregar claves foráneas
            $table->foreign('tipo_producto_id')->references('id')->on('tipo_producto')->onDelete('cascade');

            // Índices para mejorar rendimiento
            $table->index(['id_comercial', 'tipo_producto_id']);
        });
    }

    public function down(): void
    {
        Schema::table('comisiones_comercial', function (Blueprint $table) {
            // Revertir cambios
            $table->dropForeign(['tipo_producto_id']);
            $table->dropIndex(['id_comercial', 'tipo_producto_id']);

            // Restaurar nombres originales
            $table->renameColumn('valor', 'comision');
            $table->boolean('porcentual')->default(false)->after('id_comercial');
            $table->dropColumn(['tipo_producto_id', 'tipo']);
        });

        Schema::rename('comisiones_comercial', 'comercial_comision');
    }
};
