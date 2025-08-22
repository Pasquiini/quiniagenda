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
            $table->string('profile_photo_url')->nullable()->after('logo_url');
            $table->string('professional_name')->nullable()->after('profile_photo_url');
            $table->string('professional_specialty')->nullable()->after('professional_name');
            $table->text('professional_description')->nullable()->after('professional_specialty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('styles', function (Blueprint $table) {
            $table->dropColumn(['profile_photo_url', 'professional_name', 'professional_specialty', 'professional_description']);
        });
    }
};
