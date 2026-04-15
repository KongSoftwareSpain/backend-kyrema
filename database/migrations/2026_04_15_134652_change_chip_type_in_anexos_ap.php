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
        Schema::table('anexos_ap', function (Blueprint $table) {
            $table->string('nº_de_chip')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('anexos_ap', function (Blueprint $table) {
            // Can't reliably convert back to integer if it has non-integers, so keep as string or large int
            // $table->integer('nº_de_chip')->nullable()->change();
        });
    }
};
