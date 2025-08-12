<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Adicione o que estiver faltando (idempotente)
        if (!Schema::hasColumn('users', 'cancel_at_period_end')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('cancel_at_period_end')
                    ->default(false)
                    ->after('stripe_subscription_id');
            });
        }

        if (!Schema::hasColumn('users', 'current_period_end')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('current_period_end')
                    ->nullable()
                    ->after('cancel_at_period_end');
            });
        }

        if (!Schema::hasColumn('users', 'stripe_account_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('stripe_account_id')->nullable()->after('stripe_customer_id');
            });
        }

        // (Opcional) índices úteis
        if (Schema::hasColumn('users', 'stripe_customer_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('stripe_customer_id');
            });
        }
        if (Schema::hasColumn('users', 'stripe_subscription_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('stripe_subscription_id');
            });
        }
    }

    public function down(): void
    {
        // Remova apenas o que você adicionou aqui
        if (Schema::hasColumn('users', 'cancel_at_period_end')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('cancel_at_period_end');
            });
        }
        if (Schema::hasColumn('users', 'current_period_end')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('current_period_end');
            });
        }
        if (Schema::hasColumn('users', 'stripe_account_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('stripe_account_id');
            });
        }
    }
};
