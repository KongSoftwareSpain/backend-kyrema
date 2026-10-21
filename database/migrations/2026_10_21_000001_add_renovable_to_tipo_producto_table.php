<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flag 'renovable' en tipo_producto: permite desactivar la renovación de un
     * producto o subproducto desde la edición. Por defecto todo es renovable.
     *
     * Los productos que por naturaleza no se renuevan (cacerías) arrancan en
     * false: se marcan por nombre, incluyendo los subproductos de un padre que
     * lo contenga. El resto se ajusta a mano desde la edición del producto.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('tipo_producto', 'renovable')) {
            Schema::table('tipo_producto', function (Blueprint $table) {
                $table->boolean('renovable')->default(true);
            });
        }

        $idsCaceria = DB::table('tipo_producto')
            ->where('nombre', 'like', '%cacer%')
            ->pluck('id');

        if ($idsCaceria->isNotEmpty()) {
            DB::table('tipo_producto')
                ->whereIn('id', $idsCaceria)
                ->orWhereIn('padre_id', $idsCaceria)
                ->update(['renovable' => false]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tipo_producto', 'renovable')) {
            Schema::table('tipo_producto', function (Blueprint $table) {
                $table->dropColumn('renovable');
            });
        }
    }
};
