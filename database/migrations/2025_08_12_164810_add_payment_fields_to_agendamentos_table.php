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
        Schema::table('agendamentos', function (Blueprint $table) {
            $table->string('payment_status', 50)->default('Pendente');
            $table->string('payment_method', 50)->nullable();
            $table->decimal('payment_amount', 10, 2)->nullable();
            $table->string('pix_txid', 255)->nullable();
            $table->text('pix_qrcode_url')->nullable();
            $table->text('pix_copia_cola')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('agendamentos', function (Blueprint $table) {
            $table->dropColumn([
                'payment_status',
                'payment_method',
                'payment_amount',
                'pix_txid',
                'pix_qrcode_url',
                'pix_copia_cola'
            ]);
        });
    }
};
