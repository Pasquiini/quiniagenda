<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Fatura extends Model
{
     use HasFactory;

    protected $fillable = [
        'user_id',
        'cliente_id',
        'orcamento_id',
        'valor_total',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function orcamento()
    {
        return $this->belongsTo(Orcamento::class);
    }
}
