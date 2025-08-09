<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    public function index()
    {
        return Auth::user()->clientes()->latest()->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('clientes')->where(function ($query) {
                return $query->where('user_id', Auth::id());
            })],
            'phone' => 'nullable|string|max:20',
            'observacoes' => 'nullable|string',
        ]);

        $cliente = Auth::user()->clientes()->create($validated);
        return response()->json(['message' => 'Cliente adicionado com sucesso!', 'cliente' => $cliente], 201);
    }

    public function update(Request $request, Cliente $cliente)
    {
        if (Auth::id() !== $cliente->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $validated = $request->validate([
            'nome' => 'required|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('clientes')->ignore($cliente->id)->where(function ($query) {
                return $query->where('user_id', Auth::id());
            })],
            'telefone' => 'nullable|string|max:20',
            'observacoes' => 'nullable|string',
        ]);

        $cliente->update($validated);
        return response()->json(['message' => 'Cliente atualizado com sucesso!', 'cliente' => $cliente]);
    }

    public function destroy(Cliente $cliente)
    {
        if (Auth::id() !== $cliente->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $cliente->delete();
        return response()->json(['message' => 'Cliente excluído com sucesso!']);
    }

    public function show(Cliente $cliente)
    {
        return response()->json($cliente);
    }
}
