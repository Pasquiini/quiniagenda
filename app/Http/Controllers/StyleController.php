<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Style;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StyleController extends Controller
{
    public function store(Request $request)
    {
        $user = auth()->user();

        // 1. Lógica para fazer o upload do logo
        $logoUrl = $request->input('logo_url'); // Assume que já existe um logo salvo

        if ($request->hasFile('logo')) {
            // Se um novo arquivo foi enviado, salve-o no disco 'public'
            $path = $request->file('logo')->store('logos', 'public');
            $logoUrl = Storage::disk('public')->url($path);

            // Opcional: Remova o logo antigo se existir
            $oldStyle = Style::where('user_id', $user->id)->first();
            if ($oldStyle && $oldStyle->logo_url) {
                $oldPath = str_replace(Storage::disk('public')->url('/'), '', $oldStyle->logo_url);
                Storage::disk('public')->delete($oldPath);
            }
        }

        // 2. Lógica para salvar as cores e a URL do logo no banco de dados
        $style = Style::updateOrCreate(
            ['user_id' => $user->id],
            [
                'logo_url' => $logoUrl,
                'card_background_color' => $request->input('card_background_color'),
                'button_color' => $request->input('button_color'),
                'text_color' => $request->input('text_color'),
            ]
        );

        return response()->json(['message' => 'Estilização salva com sucesso!', 'style' => $style]);
    }

    /**
     * Recupera as configurações de estilo do usuário autenticado.
     */
    public function show()
    {
        $user = auth()->user();

        // Encontra as configurações de estilo do usuário logado
        $style = Style::firstOrCreate(['user_id' => $user->id]);

        return response()->json(['style' => $style]);
    }

    public function showPublic($userId)
    {
        $style = Style::where('user_id', $userId)->first();

        // Se o estilo não for encontrado, retorna as cores padrão
        if (!$style) {
            $style = new Style();
            // Apenas para simulação, você pode retornar as cores padrão aqui
        }

        return response()->json(['style' => $style]);
    }
}
