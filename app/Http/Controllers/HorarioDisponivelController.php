<?php

namespace App\Http\Controllers;

use App\Models\HorarioDisponivel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HorarioDisponivelController extends Controller
{
    public function index()
    {
        return response()->json(Auth::user()->horariosDisponiveis()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'horarios' => 'required|array',
            'horarios.*.dia_da_semana' => 'required|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'horarios.*.hora_inicio' => 'nullable|date_format:H:i',
            'horarios.*.hora_fim' => 'nullable|date_format:H:i|after:horarios.*.hora_inicio',
        ]);

        $user = Auth::user();
        $user->horariosDisponiveis()->delete();

        foreach ($validated['horarios'] as $horario) {
            if ($horario['hora_inicio'] && $horario['hora_fim']) {
                $user->horariosDisponiveis()->create([
                    'dia_da_semana' => $horario['dia_da_semana'],
                    'hora_inicio' => $horario['hora_inicio'],
                    'hora_fim' => $horario['hora_fim'],
                ]);
            }
        }

        return response()->json(['message' => 'Horários salvos com sucesso!'], 200);
    }
}
