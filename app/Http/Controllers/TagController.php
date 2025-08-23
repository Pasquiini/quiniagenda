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
        $userId = Auth::id();
        $tags = Tag::where('user_id', $userId)->get();

        return response()->json($tags);
    }

    /**
     * Cria uma nova tag para o usuário.
     */
    public function store(Request $request)
    {
        $userId = Auth::id();
        $request->validate([
            'nome' => [
                'required',
                'string',
                'max:50',
                // 💡 Garante que o nome da tag seja único para o usuário logado
                Rule::unique('tags')->where(function ($query) use ($userId) {
                    return $query->where('user_id', $userId);
                })
            ],
            'cor' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/'
        ]);

        $tag = Tag::create([
            'nome' => $request->nome,
            'cor' => $request->cor,
            'user_id' => $userId
        ]);

        return response()->json($tag, 201);
    }

    public function show(Tag $tag)
    {
        // Verifica se a tag pertence ao usuário autenticado
        if (Auth::id() !== $tag->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        return response()->json($tag);
    }

    public function update(Request $request, Tag $tag)
    {
        // Verifica se a tag pertence ao usuário autenticado
        if (Auth::id() !== $tag->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $userId = Auth::id();
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
        if (Auth::id() !== $tag->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $tag->delete();

        return response()->json(['message' => 'Tag excluída com sucesso!']);
    }
}
