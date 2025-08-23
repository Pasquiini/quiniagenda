<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    public function index()
    {
        $userId = Auth::user()->id;
        $tags = Tag::where('user_id', $userId)->get();

        return response()->json($tags);
    }

    /**
     * Cria uma nova tag para o usuário.
     */
    public function store(Request $request)
    {
        // A validação permanece a mesma, pois o `user_id` é capturado
        // e usado na regra de unicidade.
        $request->validate([
            'nome' => [
                'required',
                'string',
                'max:50',
                // A regra de unicidade usa o ID do usuário para garantir tags únicas por usuário.
                Rule::unique('tags')->where(function ($query) {
                    return $query->where('user_id', Auth::user()->id);
                })
            ],
            'cor' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/'
        ]);

        // 💡 Correção: Crie a tag usando a relação `tags()` do usuário autenticado.
        // Isso atribui o 'user_id' automaticamente.
        $tag = Auth::user()->tags()->create([
            'nome' => $request->nome,
            'cor' => $request->cor,
        ]);

        return response()->json($tag, 201);
    }

    public function show(Tag $tag)
    {
        // Verifica se a tag pertence ao usuário autenticado
        if (Auth::user()->id !== $tag->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        return response()->json($tag);
    }

    public function update(Request $request, Tag $tag)
    {
        // Verifica se a tag pertence ao usuário autenticado
        if (Auth::user()->id !== $tag->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $userId = Auth::user()->id;
        $request->validate([
            'nome' => [
                'required',
                'string',
                'max:50',
                // 💡 Garante que o nome da tag seja único para o usuário, ignorando a tag atual
                Rule::unique('tags')->ignore($tag->id)->where(function ($query) use ($userId) {
                    return $query->where('user_id', $userId);
                })
            ],
            'cor' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/'
        ]);

        $tag->update($request->only('nome', 'cor'));

        return response()->json($tag);
    }

    public function destroy(Tag $tag)
    {
        // Verifica se a tag pertence ao usuário autenticado
        if (Auth::user()->id !== $tag->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $tag->delete();

        return response()->json(['message' => 'Tag excluída com sucesso!']);
    }
}
