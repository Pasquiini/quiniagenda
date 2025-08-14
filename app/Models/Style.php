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
    ];

    /**
     * Get the user that owns the style.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
