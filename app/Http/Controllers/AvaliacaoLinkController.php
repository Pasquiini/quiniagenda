<?php

namespace App\Http\Controllers;

use App\Models\AvaliacaoLink;
use Illuminate\Http\Request;

class AvaliacaoLinkController extends Controller
{
    /**
     * Exibe a lista de links do usuário autenticado.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Retorna apenas os links do usuário logado
        return AvaliacaoLink::where('user_id', auth()->id())->get();
    }

    /**
     * Armazena um novo link.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'plataforma' => 'required|string|max:255',
            'url' => 'required|url|max:255',
            'tipo' => 'required|string|in:google,outro',
        ]);

        $link = AvaliacaoLink::create([
            'user_id' => auth()->id(),
            'plataforma' => $request->plataforma,
            'url' => $request->url,
            'tipo' => $request->tipo,
        ]);

        return response()->json($link, 201);
    }

    /**
     * Atualiza um link existente.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\AvaliacaoLink  $avaliacaoLink
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, AvaliacaoLink $avaliacaoLink)
    {
        // Garante que o usuário só possa atualizar seus próprios links
        if ($avaliacaoLink->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'plataforma' => 'required|string|max:255',
            'url' => 'required|url|max:255',
            'tipo' => 'required|string|in:google,outro',
        ]);

        $avaliacaoLink->update($request->all());

        return response()->json($avaliacaoLink);
    }

    /**
     * Remove um link.
     *
     * @param  \App\Models\AvaliacaoLink  $avaliacaoLink
     * @return \Illuminate\Http\Response
     */
    public function destroy(AvaliacaoLink $avaliacaoLink)
    {
        // Garante que o usuário só possa deletar seus próprios links
        if ($avaliacaoLink->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $avaliacaoLink->delete();

        return response()->json(null, 204);
    }
}
