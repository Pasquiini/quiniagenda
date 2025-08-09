<?php

namespace App\Mail;

use App\Models\Agendamento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificacaoReagendamento extends Mailable
{
    use Queueable, SerializesModels;

    public $agendamento;

    public function __construct(Agendamento $agendamento)
    {
        $this->agendamento = $agendamento;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Agendamento Reagendado',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notificacao-reagendamento', // A view que vamos criar
            with: ['agendamento' => $this->agendamento],
        );
    }
}
