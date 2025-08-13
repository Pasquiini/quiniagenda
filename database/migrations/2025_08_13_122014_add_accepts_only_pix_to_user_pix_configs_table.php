<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('user_pix_configs', function (Blueprint $table) {
            $table->boolean('accepts_only_pix')->default(false)->after('pix_key_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_pix_configs', function (Blueprint $table) {
            $table->dropColumn('accepts_only_pix');
        });
    }
};
