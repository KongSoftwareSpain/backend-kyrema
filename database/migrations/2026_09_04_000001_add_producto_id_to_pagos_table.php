<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arregla una referencia rota: Pago::obtenerProductoRelacionado() ya usa
 * $this->producto_id, pero la columna nunca llegó a crearse (parece que se
 * copió de 'anulaciones', que sí la tiene). Sin FK real porque la tabla de
 * producto es dinámica (una por tipo_producto).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $t) {
            $t->unsignedBigInteger('producto_id')->nullable()->after('letras_identificacion');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $t) {
            $t->dropColumn('producto_id');
        });
    }
};
