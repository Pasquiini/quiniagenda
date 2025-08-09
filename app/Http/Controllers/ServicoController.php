<?php

namespace App\Http\Controllers;

use App\Models\Servico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ServicoController extends Controller
{
    public function index()
    {
        return Auth::user()->servicos()->latest()->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255',
            'valor' => 'required|numeric|min:0',
            'duration_minutes' => 'required|integer|min:1', // Adicionado a validação
        ]);
        $servico = Auth::user()->servicos()->create($validated);
        return response()->json(['message' => 'Serviço criado com sucesso!', 'servico' => $servico], 201);
    }

    public function update(Request $request, Servico $servico)
    {
        if (Auth::id() !== $servico->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }
        $validated = $request->validate([
            'nome' => 'required|string|max:255',
            'valor' => 'required|numeric|min:0',
            'duration_minutes' => 'required|integer|min:1', // Adicionado a validação
        ]);
        $servico->update($validated);
        return response()->json(['message' => 'Serviço atualizado com sucesso!', 'servico' => $servico]);
    }

    public function destroy(Servico $servico)
    {
        if (Auth::id() !== $servico->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }
        $servico->delete();
        return response()->json(['message' => 'Serviço excluído com sucesso!']);
    }
}
