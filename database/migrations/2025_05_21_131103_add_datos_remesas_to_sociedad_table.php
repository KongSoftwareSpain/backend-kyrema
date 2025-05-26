<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sociedad', function (Blueprint $table) {
            $table->string('razon_social')->nullable()->after('nombre'); // Ajusta 'nombre' si quieres otro orden
            $table->string('bic', 11)->nullable()->after('razon_social');
            $table->string('id_acreedor_remesas', 35)->nullable()->after('bic'); // Hasta 35 caracteres según estándar SEPA
        });
    }

    public function down(): void
    {
        Schema::table('sociedad', function (Blueprint $table) {
            $table->dropColumn(['razon_social', 'bic', 'id_acreedor_remesas']);
        });
    }
};
