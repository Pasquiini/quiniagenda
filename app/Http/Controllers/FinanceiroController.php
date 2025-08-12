<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Agendamento;
use Carbon\Carbon;

class FinanceiroController extends Controller
{
    /**
     * Retorna um resumo financeiro para o profissional.
     * Inclui totais semanais, mensais e a discriminação por tipo de pagamento.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getResumo(Request $request)
    {
        $user = $request->user(); // Pega o usuário logado (profissional)

        // Definindo as datas de início e fim para a semana e o mês
        $inicioSemana = Carbon::now()->startOfWeek();
        $fimSemana = Carbon::now()->endOfWeek();
        $inicioMes = Carbon::now()->startOfMonth();
        $fimMes = Carbon::now()->endOfMonth();

        // Subquery para calcular os totais
        $subQuery = Agendamento::query()
            ->where('user_id', $user->id)
            ->where('payment_status', 'like', 'Pago%');

        // Calcula o total recebido na semana
        $totalSemana = (clone $subQuery)
            ->whereBetween('data_hora', [$inicioSemana, $fimSemana])
            ->sum('payment_amount');

        // Calcula o total recebido no mês
        $totalMes = (clone $subQuery)
            ->whereBetween('data_hora', [$inicioMes, $fimMes])
            ->sum('payment_amount');

        // Calcula a discriminação entre pagamentos online e presenciais no mês
        $discriminacaoMes = (clone $subQuery)
            ->whereBetween('data_hora', [$inicioMes, $fimMes])
            ->select('payment_method', DB::raw('SUM(payment_amount) as total'))
            ->groupBy('payment_method')
            ->get();

        return response()->json([
            'total_semana' => $totalSemana,
            'total_mes' => $totalMes,
            'discriminacao_mes' => $discriminacaoMes,
        ]);
    }

    /**
     * Retorna a lista de agendamentos com pagamento pendente.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPendencias(Request $request)
    {
        $user = $request->user(); // Pega o usuário logado (profissional)

        $pendencias = Agendamento::query()
            ->where('user_id', $user->id)
            ->with(['cliente', 'servico']) // Carrega as informações de cliente e serviço
            ->orderBy('data_hora', 'asc')
            ->get();

        return response()->json($pendencias);
    }
}
