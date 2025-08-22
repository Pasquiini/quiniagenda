<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Importe a classe

class Style extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'logo_url',
        'card_background_color',
        'button_color',
        'text_color',
        'profile_photo_url', // Novo campo para a foto de perfil
        'professional_name', // Novo campo para o nome
        'professional_specialty', // Novo campo para a especialidade
        'professional_description',
        'whatsapp_number',
        'instagram_handle',
        'facebook_handle',
    ];

    /**
     * Get the user that owns the style.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
