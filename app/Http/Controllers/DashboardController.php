<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // Contagem de orçamentos por status
        $totalOrcamentos = $user->orcamentos()->count();
        $orcamentosPendentes = $user->orcamentos()->where('status', 'pendente')->count();
        $orcamentosAprovados = $user->orcamentos()->where('status', 'aprovado')->count();
        $valorTotalAprovado = $user->orcamentos()->where('status', 'aprovado')->sum('valor');

        // Faturamento mensal
        $faturamentoMensal = $user->faturas()
            ->select(DB::raw('MONTH(created_at) as mes'), DB::raw('SUM(valor_total) as total'))
            ->groupBy('mes')
            ->orderBy('mes')
            ->get();

        // Agendamentos por serviço
        $agendamentosPorServico = $user->agendamentos()
            ->with('servico')
            ->select(DB::raw('servico_id'), DB::raw('count(*) as total'))
            ->groupBy('servico_id')
            ->get();

        // Contagem de agendamentos futuros
        $agendamentosFuturos = $user->agendamentos()->where('data_hora', '>', now())->count();
        $agendamentosRecentes = $user->agendamentos()->where('data_hora', '>', Carbon::now()->subDays(7))->latest()->limit(5)->get();

        // Contagem total de clientes
        $clientesCount = $user->clientes()->count();

        return response()->json([
            'orcamentos' => [
                'total' => $totalOrcamentos,
                'pendentes' => $orcamentosPendentes,
                'aprovados' => $orcamentosAprovados,
                'valor_total_aprovado' => $valorTotalAprovado,
            ],
            'agendamentos' => [
                'futuros' => $agendamentosFuturos,
                'recentes' => $agendamentosRecentes,
                'por_servico' => $agendamentosPorServico,
            ],
            'clientes' => [
                'total' => $clientesCount,
            ],
            'faturamento' => [
                'mensal' => $faturamentoMensal,
            ],
        ]);
    }
}
