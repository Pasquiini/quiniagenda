<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HistoricoCliente extends Model
{
    use HasFactory;

    protected $table = 'historico_clientes';

    protected $fillable = [
        'cliente_id',
        'titulo',
        'conteudo',
        'usuario_id',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class);
    }
}
