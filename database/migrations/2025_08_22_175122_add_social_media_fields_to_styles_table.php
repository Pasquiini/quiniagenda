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
        Schema::table('styles', function (Blueprint $table) {
            $table->string('whatsapp_number')->nullable();
            $table->string('instagram_handle')->nullable();
            $table->string('facebook_handle')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('styles', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_number', 'instagram_handle', 'facebook_handle']);
        });
    }
};
