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
        Schema::table('avisos_caducidad', function (Blueprint $table) {
            $table->unsignedBigInteger('perfil_id')->nullable()->after('id');
            $table->boolean('forzado')->default(false)->after('dias_aviso');

            $table->foreign('perfil_id')->references('id')->on('perfiles_envio_avisos')->onDelete('set null');
        });

        // Los envíos en modo 'dia_mes' o forzados no tienen un "días antes" asociado.
        Schema::table('avisos_caducidad', function (Blueprint $table) {
            $table->integer('dias_aviso')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('avisos_caducidad', function (Blueprint $table) {
            $table->dropForeign(['perfil_id']);
            $table->dropColumn(['perfil_id', 'forzado']);
        });
    }
};
