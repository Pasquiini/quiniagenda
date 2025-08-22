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

        // Encontra ou cria a entrada de estilo para o usuário
        $style = Style::firstOrCreate(['user_id' => $user->id]);

        // 1. Lógica para fazer o upload do logo
        if ($request->hasFile('logo')) {
            // Remova o logo antigo se existir
            if ($style->logo_url) {
                Storage::disk('public')->delete(str_replace(Storage::disk('public')->url('/'), '', $style->logo_url));
            }
            $path = $request->file('logo')->store('logos', 'public');
            $style->logo_url = Storage::disk('public')->url($path);
        }

        // 2. Lógica para fazer o upload da foto de perfil
        if ($request->hasFile('profile_photo')) {
            // Remova a foto antiga se existir
            if ($style->profile_photo_url) {
                Storage::disk('public')->delete(str_replace(Storage::disk('public')->url('/'), '', $style->profile_photo_url));
            }
            $path = $request->file('profile_photo')->store('profile_photos', 'public');
            $style->profile_photo_url = Storage::disk('public')->url($path);
        }

        // 3. Salvar os dados do formulário
        $style->card_background_color = $request->input('card_background_color');
        $style->button_color = $request->input('button_color');
        $style->text_color = $request->input('text_color');
        $style->professional_name = $request->input('name'); // Mapeia para 'name' do frontend
        $style->professional_specialty = $request->input('specialty');
        $style->professional_description = $request->input('description');
        $style->whatsapp_number = $request->input('whatsapp_number');
        $style->instagram_handle = $request->input('instagram_handle');
        $style->facebook_handle = $request->input('facebook_handle');
        $style->save();

        return response()->json([
            'message' => 'Configurações salvas com sucesso!',
            'style' => $style,
        ]);
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

        if (!$style) {
            $style = new Style([
                'logo_url' => null,
                'profile_photo_url' => null,
                'professional_name' => 'Profissional',
                'professional_specialty' => 'Especialidade',
                'professional_description' => 'Descrição do profissional...',
                'whatsapp_number' => null,
                'instagram_handle' => null,
                'facebook_handle' => null,
                'card_background_color' => '#ffffff',
                'button_color' => '#0d6efd',
                'text_color' => '#212529'
            ]);
        }

        if ($style->logo_url) {
            $style->logo_url = asset(str_replace('storage/', 'public/', $style->logo_url));
        }
        if ($style->profile_photo_url) {
            $style->profile_photo_url = asset(str_replace('storage/', 'public/', $style->profile_photo_url));
        }

        return response()->json(['style' => $style]);
    }
}
