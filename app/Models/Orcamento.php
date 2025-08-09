<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Orcamento extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'cliente_id',
        'valor',
        'status',
        'uuid',
        'expires_at',
        'aprovado_por',
        'aprovado_em',
        'endereco_servico',
        'data_servico',
        'horas_servico',
        'observacoes',
        'prazo_de_entrega',
        'desconto',
        'impostos',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'aprovado_em' => 'datetime',
        'data_servico' => 'datetime',
        'prazo_de_entrega' => 'date',

    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

     public function servicos()
    {
        return $this->belongsToMany(Servico::class);
    }
    public function itens()
    {
        return $this->hasMany(ItemOrcamento::class);
    }
}
