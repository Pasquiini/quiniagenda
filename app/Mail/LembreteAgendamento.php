<?php

namespace App\Mail;

use App\Models\Agendamento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LembreteAgendamento extends Mailable
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
            subject: 'Lembrete de Agendamento',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lembrete-agendamento', // A view que vamos criar
            with: ['agendamento' => $this->agendamento],
        );
    }
}
