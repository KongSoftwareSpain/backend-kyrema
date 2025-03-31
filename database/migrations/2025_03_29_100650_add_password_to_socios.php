<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('socios', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email'); // Ajusta la posición según tu esquema
        });
    }

    public function down(): void
    {
        Schema::table('socios', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
