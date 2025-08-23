<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'nome',
        'cor',
        'empresa_id'
    ];

    public function clientes(): BelongsToMany
    {
        return $this->belongsToMany(Cliente::class);
    }
}
