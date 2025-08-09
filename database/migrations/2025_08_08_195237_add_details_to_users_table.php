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
        Schema::table('users', function (Blueprint $table) {
            $table->string('fantasia')->nullable()->after('name');
            $table->string('document')->unique()->nullable()->after('phone');
            $table->string('address')->nullable()->after('document');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('fantasia');
            $table->dropColumn('document');
            $table->dropColumn('address');
        });
    }
};
