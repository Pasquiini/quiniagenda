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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
             $table->string('name'); // Nome do plano (Ex: Gratuito, Orçamentos, Agendamentos)
            $table->text('description')->nullable(); // Descrição do plano
            $table->decimal('price', 8, 2)->default(0); // Preço do plano
            $table->integer('max_budgets')->nullable(); // Limite de orçamentos (null para ilimitado)
            $table->integer('max_appointments')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
