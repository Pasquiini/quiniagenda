<?php

namespace App\Console\Commands;

use App\Models\Agendamento;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CancelUnpaidAppointments extends Command
{
    /**
     * O nome e a assinatura do comando do console.
     *
     * @var string
     */
    protected $signature = 'appointments:cancel-unpaid';

    /**
     * A descrição do comando do console.
     *
     * @var string
     */
    protected $description = 'Cancela agendamentos com pagamento pendente há mais de 30 minutos.';

    /**
     * Executa o comando do console.
     *
     * @return int
     */
    public function handle()
    {
        // Define o tempo limite de 30 minutos
        $limiteTempo = Carbon::now()->subMinutes(30);

        // Busca agendamentos com status 'pendente' e criados há mais de 30 minutos
        $agendamentosParaCancelar = Agendamento::where('payment_status', 'pendente')
            ->where('created_at', '<', $limiteTempo)
            ->get();

        $count = 0;

        foreach ($agendamentosParaCancelar as $agendamento) {
            $agendamento->status = 'cancelado';
            $agendamento->save();
            $count++;
        }

        $this->info("{$count} agendamento(s) não pago(s) foram cancelados.");

        return 0;
    }
}
