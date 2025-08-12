<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Agendamento extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'cliente_id',
        'servico_id',
        'data_hora',
        'observacoes',
        'status',
        'payment_status',
        'payment_method',
        'payment_amount',
        'pix_txid',
        'pix_qrcode_url',
        'pix_copia_cola',
        'payment_option'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function servico()
    {
        return $this->belongsTo(Servico::class);
    }
}
