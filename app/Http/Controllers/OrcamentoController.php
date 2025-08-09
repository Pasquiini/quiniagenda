<?php

namespace App\Http\Controllers;

use App\Models\Fatura;
use App\Models\Orcamento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

class OrcamentoController extends Controller
{
    public function index()
    {
        return Auth::user()->orcamentos()->with(['itens', 'cliente', 'servicos'])->latest()->get();
    }

    public function show(Orcamento $orcamento)
    {
        if (Auth::id() !== $orcamento->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }
        return $orcamento->load(['itens', 'cliente', 'servicos']);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if ($user->plan->max_budgets !== null && $user->orcamentos()->count() >= $user->plan->max_budgets) {
            return response()->json(['message' => 'Limite de orçamentos atingido.'], 403);
        }

        $validated = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id', Rule::in(Auth::user()->clientes()->pluck('id'))],
            'servico_ids' => 'required|array|min:1',
            'servico_ids.*' => 'required|exists:servicos,id',
            'endereco_servico' => 'nullable|string|max:255',
            'data_servico' => 'nullable|date',
            'horas_servico' => 'nullable|numeric|min:0',
            'observacoes' => 'nullable|string',
            'desconto' => 'nullable|numeric|min:0',
            'impostos' => 'nullable|numeric|min:0',
            'itens' => 'required|array|min:1',
            'itens.*.descricao' => 'required|string',
            'itens.*.quantidade' => 'required|numeric|min:0',
            'itens.*.valor_unitario' => 'required|numeric|min:0',
        ]);

        $valorTotal = collect($validated['itens'])->sum(function ($item) {
            return $item['quantidade'] * $item['valor_unitario'];
        });

        $orcamento = $user->orcamentos()->create([
            'cliente_id' => $validated['cliente_id'],
            'valor' => $valorTotal,
            'status' => 'pendente',
            'uuid' => (string) Str::uuid(),
            'expires_at' => Carbon::now()->addDays(30),
            'endereco_servico' => $validated['endereco_servico'] ?? null,
            'data_servico' => $validated['data_servico'] ?? null,
            'horas_servico' => $validated['horas_servico'] ?? null,
            'observacoes' => $validated['observacoes'] ?? null,
            'desconto' => $validated['desconto'] ?? 0,
            'impostos' => $validated['impostos'] ?? 0,
        ]);

        $orcamento->servicos()->attach($validated['servico_ids']);
        $orcamento->itens()->createMany($validated['itens']);

        return response()->json(['message' => 'Orçamento criado com sucesso!', 'orcamento' => $orcamento->load(['itens', 'servicos'])], 201);
    }

    public function update(Request $request, Orcamento $orcamento)
    {
        if (Auth::id() !== $orcamento->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $validated = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id', Rule::in(Auth::user()->clientes()->pluck('id'))],
            'servico_ids' => 'required|array|min:1',
            'servico_ids.*' => 'required|exists:servicos,id',
            'endereco_servico' => 'nullable|string|max:255',
            'data_servico' => 'nullable|date',
            'horas_servico' => 'nullable|numeric|min:0',
            'observacoes' => 'nullable|string',
            'desconto' => 'nullable|numeric|min:0',
            'impostos' => 'nullable|numeric|min:0',
            'itens' => 'required|array|min:1',
            'itens.*.descricao' => 'required|string',
            'itens.*.quantidade' => 'required|numeric|min:0',
            'itens.*.valor_unitario' => 'required|numeric|min:0',
        ]);

        $valorTotal = collect($validated['itens'])->sum(function ($item) {
            return $item['quantidade'] * $item['valor_unitario'];
        });

        $orcamento->update([
            'cliente_id' => $validated['cliente_id'],
            'valor' => $valorTotal,
            'status' => $request->status,
            'endereco_servico' => $validated['endereco_servico'] ?? null,
            'data_servico' => $validated['data_servico'] ?? null,
            'horas_servico' => $validated['horas_servico'] ?? null,
            'observacoes' => $validated['observacoes'] ?? null,
            'desconto' => $validated['desconto'] ?? 0,
            'impostos' => $validated['impostos'] ?? 0,
        ]);

        $orcamento->servicos()->sync($validated['servico_ids']);
        $orcamento->itens()->delete();
        $orcamento->itens()->createMany($validated['itens']);

        return response()->json(['message' => 'Orçamento atualizado com sucesso!', 'orcamento' => $orcamento->load(['itens', 'servicos'])]);
    }

    public function destroy(Orcamento $orcamento)
    {
        if (Auth::id() !== $orcamento->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $orcamento->delete();
        return response()->json(['message' => 'Orçamento excluído com sucesso!']);
    }

    public function getStatistics()
    {
        $user = Auth::user();

        $totalOrcamentos = $user->orcamentos()->count();
        $orcamentosPendentes = $user->orcamentos()->where('status', 'pendente')->count();
        $orcamentosAprovados = $user->orcamentos()->where('status', 'aprovado')->count();
        $valorTotalAprovado = $user->orcamentos()->where('status', 'aprovado')->sum('valor');

        return response()->json([
            'total_orcamentos' => $totalOrcamentos,
            'orcamentos_pendentes' => $orcamentosPendentes,
            'orcamentos_aprovados' => $orcamentosAprovados,
            'valor_total_aprovado' => $valorTotalAprovado,
        ]);
    }

    public function faturar(Orcamento $orcamento)
    {
        if (Auth::id() !== $orcamento->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        if ($orcamento->status !== 'aprovado') {
            return response()->json(['message' => 'Apenas orçamentos aprovados podem ser faturados.'], 409);
        }

        $fatura = Fatura::create([
            'user_id' => $orcamento->user_id,
            'cliente_id' => $orcamento->cliente_id,
            'orcamento_id' => $orcamento->id,
            'valor_total' => $orcamento->valor,
            'status' => 'pendente'
        ]);

        return response()->json(['message' => 'Orçamento faturado com sucesso!', 'fatura' => $fatura]);
    }

    public function generatePdf(Orcamento $orcamento)
    {
        if (Auth::id() !== $orcamento->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $orcamento->load(['itens', 'cliente', 'servicos']);

        $pdf = App::make('dompdf.wrapper');
        $pdf->loadView('pdfs.orcamento-pdf', compact('orcamento'));

        return $pdf->download("orcamento-{$orcamento->id}.pdf");
    }
}
