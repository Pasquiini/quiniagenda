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
        Schema::table('agendamentos', function (Blueprint $table) {
            $table->dropColumn('cliente_nome');
            $table->dropColumn('cliente_telefone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agendamentos', function (Blueprint $table) {
            $table->string('cliente_nome')->after('cliente_id');
            $table->string('cliente_telefone')->nullable()->after('cliente_nome');
        });
    }
};
