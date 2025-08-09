<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Agendamento;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;
use Carbon\Carbon; // Importa a biblioteca Carbon para formatação de datas

class SendWhatsappMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $agendamento;

    public function __construct(Agendamento $agendamento)
    {
        $this->agendamento = $agendamento;
    }

    public function handle()
    {
        $sid = env('TWILIO_SID');
        $token = env('TWILIO_AUTH_TOKEN');
        $twilioWhatsappNumber = env('TWILIO_WHATSAPP_NUMBER');

        $agendamento = $this->agendamento;
        $cliente = $agendamento->cliente;
        $profissional = $agendamento->user;
        $servico = $agendamento->servico;

        if (!$profissional || !$cliente || !$servico) {
            Log::error("Erro: Dados de agendamento incompletos para o agendamento {$agendamento->id}.");
            return;
        }

        $dataHora = Carbon::parse($agendamento->data_hora);
        $data = $dataHora->format('d/m/Y');
        $hora = $dataHora->format('H:i');
        $enderecoProfissional = $profissional->address ?? 'Endereço não informado';

        // Lógica para determinar a mensagem com base no status
        $mensagemCliente = '';
        $mensagemProfissional = '';

        switch ($agendamento->status) {
            case 'confirmado':
                $mensagemCliente = "✅ Olá {$cliente->nome}, seu agendamento foi CONFIRMADO!\n";
                $mensagemCliente .= "Profissional: {$profissional->name}\n";
                $mensagemCliente .= "Serviço: {$servico->nome}\n";
                $mensagemCliente .= "Data: {$data}\n";
                $mensagemCliente .= "Hora: {$hora}\n";
                $mensagemCliente .= "Endereço: {$enderecoProfissional}\n\n";
                $mensagemCliente .= "Aguardamos você!";

                $mensagemProfissional = "✅ Olá {$profissional->name}, o agendamento de {$cliente->nome} foi CONFIRMADO!\n";
                $mensagemProfissional .= "Serviço: {$servico->nome}\n";
                $mensagemProfissional .= "Data e Hora: {$data} às {$hora}\n";
                $mensagemProfissional .= "Telefone do Cliente: {$cliente->phone}";
                break;

            case 'cancelado':
                $mensagemCliente = "❌ Olá {$cliente->nome}, seu agendamento com {$profissional->name} foi CANCELADO.\n";
                $mensagemCliente .= "Serviço: {$servico->nome}\n";
                $mensagemCliente .= "Data e Hora original: {$data} às {$hora}.";

                $mensagemProfissional = "❌ Olá {$profissional->name}, o agendamento de {$cliente->nome} para o serviço {$servico->nome} foi CANCELADO.";
                break;

            case 'reagendado':
                $mensagemCliente = "🔄 Olá {$cliente->nome}, seu agendamento com {$profissional->name} foi REAGENDADO!\n";
                $mensagemCliente .= "Nova Data: {$data}\n";
                $mensagemCliente .= "Nova Hora: {$hora}\n";
                $mensagemCliente .= "Serviço: {$servico->nome}";

                $mensagemProfissional = "🔄 Olá {$profissional->name}, o agendamento de {$cliente->nome} foi REAGENDADO!\n";
                $mensagemProfissional .= "Nova Data e Hora: {$data} às {$hora}\n";
                $mensagemProfissional .= "Telefone do Cliente: {$cliente->phone}";
                break;

            case 'pendente':
            default:
                // Sua lógica original para um novo agendamento, que por padrão é 'pendente'
                $mensagemCliente = "👋 Olá {$cliente->nome}, seu agendamento com {$profissional->name} foi criado e está PENDENTE de confirmação!\n";
                $mensagemCliente .= "Profissional: {$profissional->name}\n";
                $mensagemCliente .= "Serviço: {$servico->nome}\n";
                $mensagemCliente .= "Data: {$data}\n";
                $mensagemCliente .= "Hora: {$hora}\n";
                $mensagemCliente .= "Endereço: {$enderecoProfissional}\n\n";
                $mensagemCliente .= "Em breve, você receberá uma confirmação. Obrigado!";

                $mensagemProfissional = "🔔 Olá {$profissional->name}, você tem um NOVO agendamento PENDENTE!\n";
                $mensagemProfissional .= "Cliente: {$cliente->nome}\n";
                $mensagemProfissional .= "Serviço: {$servico->nome}\n";
                $mensagemProfissional .= "Data e Hora: {$data} às {$hora}\n";
                $mensagemProfissional .= "Telefone do Cliente: {$cliente->phone}";
                break;
        }

        // Restante do código de envio... (sem mudanças)
        $client = new Client($sid, $token);

        try {
            if ($cliente->phone) {
                $client->messages->create(
                    "whatsapp:{$this->formatPhoneNumber($cliente->phone)}",
                    ["from" => $twilioWhatsappNumber, "body" => $mensagemCliente]
                );
            } else {
                Log::warning("Aviso: O cliente {$cliente->nome} (ID: {$cliente->id}) não tem um número de telefone cadastrado.");
            }

            if ($profissional->phone) {
                $client->messages->create(
                    "whatsapp:{$this->formatPhoneNumber($profissional->phone)}",
                    ["from" => $twilioWhatsappNumber, "body" => $mensagemProfissional]
                );
            } else {
                Log::warning("Aviso: O profissional {$profissional->name} (ID: {$profissional->id}) não tem um número de telefone cadastrado.");
            }

            Log::info('Tentativa de envio de mensagens de WhatsApp concluída.');
        } catch (\Exception $e) {
            Log::error("Erro ao enviar mensagens de WhatsApp: " . $e->getMessage());
        }
    }

    private function formatPhoneNumber(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleaned) === 10) {
            $cleaned = '55' . substr($cleaned, 0, 2) . '9' . substr($cleaned, 2);
        } elseif (strlen($cleaned) === 11) {
            $cleaned = '55' . $cleaned;
        }
        return "+{$cleaned}";
    }
}
