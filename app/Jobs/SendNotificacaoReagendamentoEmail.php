<?php

namespace App\Jobs;

use App\Mail\NotificacaoReagendamento;
use App\Models\Agendamento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendNotificacaoReagendamentoEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $agendamento;

    public function __construct(Agendamento $agendamento)
    {
        $this->agendamento = $agendamento;
    }

    public function handle(): void
    {
        Mail::to($this->agendamento->cliente->email)->send(new NotificacaoReagendamento($this->agendamento));
    }
}
