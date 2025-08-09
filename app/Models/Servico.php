<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Servico extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'nome',
        'valor',
        'duration_minutes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
