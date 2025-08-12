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
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('cpf_cnpj')->nullable(); // Adiciona a nova coluna
        });
    }

    public function down()
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('cpf_cnpj');
        });
    }
};
