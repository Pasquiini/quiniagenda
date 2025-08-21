<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'nome',
        'email',
        'telefone',
        'observacoes',
        'phone',
        'cpf_cnpj'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

     public function historico()
    {
        return $this->hasMany(HistoricoCliente::class)->latest();
    }

    public function agendamentos()
    {
        return $this->hasMany(Agendamento::class);
    }
}
