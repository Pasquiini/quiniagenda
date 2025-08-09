<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->foreignId('servico_id')->nullable()->constrained()->onDelete('set null');
            $table->dropColumn('descricao_servico'); // Remove a coluna antiga
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropForeign(['servico_id']);
            $table->dropColumn('servico_id');
            $table->text('descricao_servico')->nullable(); // Adiciona a coluna de volta para o rollback
        });
    }
};
