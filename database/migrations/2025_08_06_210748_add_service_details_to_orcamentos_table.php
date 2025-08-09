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
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->string('endereco_servico')->nullable()->after('servico_id');
            $table->dateTime('data_servico')->nullable()->after('endereco_servico');
            $table->decimal('horas_servico', 8, 2)->nullable()->after('data_servico');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            //
        });
    }
};
