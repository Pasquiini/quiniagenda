<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HistoricoClienteController extends Controller
{
    /**
     * Lista o histórico de um cliente.
     */
    public function index(Cliente $cliente)
    {
        $historico = $cliente->historico()->with('user', 'agendamentos')->get();
        return response()->json($historico);
    }

    /**
     * Adiciona um novo registro ao histórico.
     */
    public function store(Request $request, Cliente $cliente)
    {
        $request->validate([
            'titulo' => 'required|string|max:255',
            'conteudo' => 'required|string',
        ]);

        $historico = $cliente->historico()->create([
            'titulo' => $request->titulo,
            'conteudo' => $request->conteudo,
            'usuario_id' => Auth::user()->id,
        ]);

        return response()->json($historico, 201);
    }
}
