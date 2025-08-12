<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\UserPixConfig;

class PixController extends Controller
{
    /**
     * Salva ou atualiza a configuração do Pix para o usuário autenticado.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Valida os dados da requisição
        $validated = $request->validate([
            'pix_key' => 'required|string|max:255',
            'pix_key_type' => 'nullable|string|max:255',
        ]);

        // Salva ou atualiza a configuração
        $pixConfig = UserPixConfig::updateOrCreate(
            ['user_id' => $user->id],
            $validated
        );

        return response()->json([
            'message' => 'Configuração Pix salva com sucesso!',
            'data' => $pixConfig,
        ], 200);
    }

    /**
     * Retorna a configuração do Pix do usuário autenticado.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show()
    {
        $user = Auth::user();

        $pixConfig = UserPixConfig::where('user_id', $user->id)->first();

        if ($pixConfig) {
            return response()->json($pixConfig, 200);
        }

        return response()->json([
            'message' => 'Nenhuma configuração Pix encontrada.',
            'data' => null,
        ], 404);
    }

    public function destroy()
    {
        $user = Auth::user();

        $pixConfig = UserPixConfig::where('user_id', $user->id)->first();

        if ($pixConfig) {
            $pixConfig->delete();
            return response()->json(['message' => 'Configuração Pix removida com sucesso!'], 200);
        }

        return response()->json(['message' => 'Nenhuma configuração Pix para remover.'], 404);
    }
}
