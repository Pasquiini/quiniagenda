<?php

namespace App\Http\Controllers;

use App\Models\Fatura;
use App\Models\Orcamento;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PublicOrcamentoController extends Controller
{
    public function show($uuid)
    {
        $orcamento = Orcamento::where('uuid', $uuid)->with(['itens', 'cliente', 'servico'])->firstOrFail();

        if ($orcamento->expires_at && $orcamento->expires_at->isPast()) {
            return response()->json(['message' => 'Este orçamento expirou.'], 410);
        }

        return response()->json($orcamento);
    }

    public function approve(Request $request, $uuid)
    {
        $orcamento = Orcamento::where('uuid', $uuid)->firstOrFail();

        if ($orcamento->expires_at && $orcamento->expires_at->isPast()) {
            return response()->json(['message' => 'Não é possível aprovar um orçamento expirado.'], 410);
        }

        if ($orcamento->status !== 'pendente') {
            return response()->json(['message' => 'O orçamento já foi ' . $orcamento->status . '.'], 409);
        }

        $orcamento->aprovado_por = $orcamento->cliente->nome;
        $orcamento->aprovado_em = Carbon::now();
        $orcamento->status = 'aprovado';
        $orcamento->save();

        $fatura = Fatura::create([
            'user_id' => $orcamento->user_id,
            'cliente_id' => $orcamento->cliente_id,
            'orcamento_id' => $orcamento->id,
            'valor_total' => $orcamento->valor,
            'status' => 'pendente'
        ]);

        return response()->json(['message' => 'Orçamento aprovado com sucesso!', 'orcamento' => $orcamento, 'fatura' => $fatura]);
    }
}
