<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Plan::create([
            'name' => 'Gratuito',
            'description' => 'Plano básico para iniciar.',
            'price' => 0,
            'max_budgets' => 10,
            'max_appointments' => 0,
        ]);

        Plan::create([
            'name' => 'Orçamentos',
            'description' => 'Acesso ilimitado a orçamentos.',
            'price' => 29.90,
            'max_budgets' => null, // null = ilimitado
            'max_appointments' => 0,
        ]);

        Plan::create([
            'name' => 'Agendamentos',
            'description' => 'Acesso ilimitado a agendamentos.',
            'price' => 49.90,
            'max_budgets' => 0,
            'max_appointments' => null,
        ]);

        Plan::create([
            'name' => 'Completo',
            'description' => 'Acesso total a todas as funcionalidades.',
            'price' => 79.90,
            'max_budgets' => null,
            'max_appointments' => null,
        ]);
    }
}
