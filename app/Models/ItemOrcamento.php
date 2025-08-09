<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemOrcamento extends Model
{
    use HasFactory;

    protected $fillable = [
        'orcamento_id',
        'descricao',
        'quantidade',
        'valor_unitario',
    ];

    public function orcamento()
    {
        return $this->belongsTo(Orcamento::class);
    }
}
