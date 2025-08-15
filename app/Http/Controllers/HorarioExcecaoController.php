<?php

namespace App\Http\Controllers;

use App\Models\HorarioExcecao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HorarioExcecaoController extends Controller
{
    /**
     * Retorna todas as exceções de horário do usuário autenticado.
     */
    public function index()
    {
        return Auth::user()->horarioExcecoes()->latest()->get();
    }

    /**
     * Salva uma nova exceção de horário.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
        ]);

        $excecao = Auth::user()->horarioExcecoes()->create($validated);

        return response()->json(['message' => 'Exceção de horário salva com sucesso!', 'excecao' => $excecao], 201);
    }

    /**
     * Deleta uma exceção de horário específica.
     */
    public function destroy(HorarioExcecao $horarioExcecao)
    {
        if (Auth::id() !== $horarioExcecao->user_id) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $horarioExcecao->delete();

        return response()->json(['message' => 'Exceção de horário excluída com sucesso.'], 200);
    }
}
