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
        Schema::table('pagos', function (Blueprint $table) {
            // Redsys fields
            $table->string('auth_code', 32)->nullable()->after('currency');
            $table->string('response_code', 10)->nullable()->after('auth_code');
            $table->string('response_message', 255)->nullable()->after('response_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn(['auth_code', 'response_code', 'response_message']);
        });
    }
};
