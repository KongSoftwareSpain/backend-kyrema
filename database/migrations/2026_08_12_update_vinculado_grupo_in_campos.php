<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Actualizar el grupo de todos los campos "vinculado" a "datos_asegurado"
        DB::table('campos')
            ->where('nombre_codigo', 'vinculado')
            ->update(['grupo' => 'datos_asegurado']);
    }

    public function down(): void
    {
        // Revertir el grupo a null
        DB::table('campos')
            ->where('nombre_codigo', 'vinculado')
            ->update(['grupo' => null]);
    }
};
