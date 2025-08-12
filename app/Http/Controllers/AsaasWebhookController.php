<?php

namespace App\Http\Controllers;

use App\Models\Agendamento;
use Illuminate\Http\Request;

class AsaasWebhookController extends Controller
{
    /**
     * Processa as notificações de webhook do Asaas.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handle(Request $request)
    {
        // 1. Opcional: Verificação de segurança
        // O Asaas recomenda verificar se a requisição é legítima usando uma chave de API.
        // Você pode implementar essa verificação aqui, se desejar.

        $data = $request->all();

        // 2. Verifica se o evento é de um pagamento confirmado
        if (isset($data['event']) && $data['event'] === 'PAYMENT_RECEIVED' && isset($data['payment'])) {
            $payment = $data['payment'];
            $agendamentoId = $payment['externalReference'] ?? null;

            if ($agendamentoId) {
                // 3. Encontra o agendamento usando o externalReference
                $agendamento = Agendamento::find($agendamentoId);

                if ($agendamento) {
                    // 4. Atualiza o status do agendamento
                    $agendamento->update([
                        'payment_status' => 'Pago Online',
                        'payment_method' => 'Pix Online',
                        // Você pode adicionar mais informações do pagamento, como o valor final, se necessário.
                    ]);

                    // 5. Retorna uma resposta de sucesso para o Asaas
                    return response()->json(['message' => 'Webhook recebido e processado com sucesso!'], 200);
                }
            }
        }

        // Se o evento não for de pagamento ou se o agendamento não for encontrado, retorna um erro
        // Retornar um 200 mesmo em caso de erro evita que o Asaas reenvie a notificação.
        return response()->json(['message' => 'Evento não processado ou agendamento não encontrado.'], 200);
    }
}
