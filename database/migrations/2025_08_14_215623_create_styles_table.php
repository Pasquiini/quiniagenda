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
        Schema::create('styles', function (Blueprint $table) {
            $table->id();
            // Adicione a coluna para a chave estrangeira do usuário
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->string('logo_url')->nullable();
            $table->string('card_background_color')->default('#ffffff');
            $table->string('button_color')->default('#0d6efd');
            $table->string('text_color')->default('#212529');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('styles');
    }
};
