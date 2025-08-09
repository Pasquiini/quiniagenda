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
        Schema::create('item_orcamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orcamento_id')->constrained()->onDelete('cascade');
            $table->string('descricao');
            $table->decimal('quantidade', 8, 2);
            $table->decimal('valor_unitario', 8, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_orcamentos');
    }
};
