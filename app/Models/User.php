<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'plan_id',
        'fantasia',
        'document',
        'address',
        'stripe_subscription_id',
        'stripe_customer_id',
        'phone',
        'cancel_at_period_end',
        'current_period_end'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];


    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'plan_id' => 'integer',
            'stripe_subscription_id' => 'string',
            'cancel_at_period_end' => 'boolean',
            'current_period_end'   => 'datetime',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function orcamentos()
    {
        return $this->hasMany(Orcamento::class);
    }

    public function servicos()
    {
        return $this->hasMany(Servico::class);
    }

    public function agendamentos()
    {
        return $this->hasMany(Agendamento::class);
    }

    public function clientes()
    {
        return $this->hasMany(Cliente::class);
    }

    public function faturas()
    {
        return $this->hasMany(Fatura::class);
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function horariosDisponiveis()
    {
        return $this->hasMany(HorarioDisponivel::class);
    }

    public function horarioExcecoes()
    {
        return $this->hasMany(HorarioExcecao::class);
    }

    public function pixConfig(): HasOne
    {
        return $this->hasOne(UserPixConfig::class);
    }

    public function style(): HasOne
    {
        return $this->hasOne(Style::class);
    }
}
