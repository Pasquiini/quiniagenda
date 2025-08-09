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
            $table->text('observacoes')->nullable()->after('horas_servico');
            $table->date('prazo_de_entrega')->nullable()->after('observacoes');
            $table->decimal('desconto', 8, 2)->default(0)->after('prazo_de_entrega');
            $table->decimal('impostos', 8, 2)->default(0)->after('desconto');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropColumn('observacoes');
            $table->dropColumn('prazo_de_entrega');
            $table->dropColumn('desconto');
            $table->dropColumn('impostos');
        });
    }
};
