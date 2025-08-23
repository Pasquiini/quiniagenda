<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::id();

        $query = Cliente::where('user_id', $userId)
            // Garante que as tags sejam carregadas junto com os clientes
            ->with('tags');

        // Lógica de filtro por termo de busca (mantido do código original)
        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('nome', 'like', "%{$searchTerm}%")
                    ->orWhere('email', 'like', "%{$searchTerm}%")
                    ->orWhere('phone', 'like', "%{$searchTerm}%");
            });
        }

        // 🆕 Lógica de filtro por tags
        if ($request->has('tag_ids')) {
            $tagIds = explode(',', $request->input('tag_ids'));
            $query->whereHas('tags', function ($q) use ($tagIds) {
                $q->whereIn('tags.id', $tagIds);
            });
        }

        return response()->json($query->latest()->get());
    }

    /**
     * Cria um novo cliente e associa as tags.
     */
    public function store(Request $request)
    {
        $userId = Auth::id();

        $validated = $request->validate([
            'nome' => 'required|string|max:255',
            // 💡 Validação de e-mail por usuário (não por empresa)
            'email' => ['nullable', 'email', 'max:255', Rule::unique('clientes')->where(function ($query) use ($userId) {
                return $query->where('user_id', $userId);
            })],
            'phone' => 'nullable|string|max:20',
            'observacoes' => 'nullable|string',
            // Validação do array de tags
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ]);

        // Cria o cliente e associa ao usuário autenticado
        $cliente = Auth::user()->clientes()->create($validated);

        // Sincroniza as tags
        if (isset($validated['tag_ids'])) {
            $cliente->tags()->sync($validated['tag_ids']);
        }

        return response()->json(['message' => 'Cliente adicionado com sucesso!', 'cliente' => $cliente], 201);
    }

    /**
     * Atualiza um cliente e suas tags.
     */
    public function update(Request $request, Cliente $cliente)
    {
        // Verifica se o cliente pertence ao usuário
        if (Auth::id() !== $cliente->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $validated = $request->validate([
            'nome' => 'required|string|max:255',
            // 💡 Validação de e-mail por usuário, ignorando o cliente atual
            'email' => ['nullable', 'email', 'max:255', Rule::unique('clientes')->ignore($cliente->id)->where(function ($query) {
                return $query->where('user_id', Auth::id());
            })],
            'phone' => 'nullable|string|max:20',
            'observacoes' => 'nullable|string',
            // Validação do array de tags
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ]);

        // Atualiza os dados do cliente
        $cliente->update($validated);

        // Sincroniza as tags
        if (isset($validated['tag_ids'])) {
            $cliente->tags()->sync($validated['tag_ids']);
        }

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
        $cliente->load('historico', 'agendamentos.servico');
        return response()->json($cliente);
    }
}
