<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HorarioDisponivel extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'dia_da_semana',
        'hora_inicio',
        'hora_fim',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Adicione um scope para simplificar a consulta de horários
    public function scopeDisponivelParaAgendamento($query, $dataHora)
    {
        $diaSemana = strtolower(Carbon::parse($dataHora)->format('l'));
        $hora = Carbon::parse($dataHora)->format('H:i:s');

        return $query->where('dia_da_semana', $diaSemana)
            ->where('hora_inicio', '<=', $hora)
            ->where('hora_fim', '>', $hora);
    }
}
