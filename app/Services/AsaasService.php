<?php

namespace App\Services;

use App\Models\Cliente;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AsaasService
{
    protected $apiKey;
    protected $baseUrl;

    public function __construct()
    {
        // Construtor: Pega a chave da API e a URL base do arquivo .env
        $this->apiKey = env('ASAAS_API_KEY');
        $this->baseUrl = env('ASAAS_BASE_URL');
    }

    public function gerarCobrancaPix(float $valor, string $descricao, Cliente $cliente, string $agendamentoId)
    {
        Log::info('Base URL: ' . $this->baseUrl);
        try {
            $asaasClienteId = $this->gerarOuBuscarClienteAsaas($cliente);
            if (!$asaasClienteId) {
                return null;
            }

            // 1. Cria o pagamento
            $paymentResponse = Http::withHeaders([
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/payments", [
                'customer' => $asaasClienteId,
                'billingType' => 'PIX',
                'value' => $valor,
                'description' => $descricao,
                'dueDate' => now()->addDays(1)->format('Y-m-d'),
                'externalReference' => $agendamentoId,
                'notificationUrl' => config('app.url') . '/api/asaas-webhook'
            ]);

            if (!$paymentResponse->successful()) {
                \Log::error('Erro ao criar pagamento no Asaas: ' . $paymentResponse->body());
                return null;
            }

            $paymentId = $paymentResponse->json()['id'];

            // 2. Obtém os dados do QR Code Pix usando o ID do pagamento
            $pixQrCodeResponse = Http::withHeaders([
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get("{$this->baseUrl}/payments/{$paymentId}/pixQrCode", [
                'type' => 'STATIC' // NOVO: Força a geração de um QR Code estático
            ]);

            if (!$pixQrCodeResponse->successful()) {
                \Log::error('Erro ao obter QR Code Pix do Asaas: ' . $pixQrCodeResponse->body());
                return null;
            }

            $pixData = $pixQrCodeResponse->json();

            // Combina as respostas para retornar os dados completos
            return [
                'id' => $paymentId,
                'encodedImage' => $pixData['encodedImage'],
                'payload' => $pixData['payload'],
            ];
        } catch (\Exception $e) {
            \Log::error('Exceção ao gerar Pix no Asaas: ' . $e->getMessage());
            return null;
        }
    }
    /**
     * Busca ou cria um cliente na Asaas com base no e-mail.
     *
     * @param Cliente $cliente O objeto do cliente do seu banco de dados.
     * @return string|null O ID do cliente na Asaas ou nulo.
     */
    private function gerarOuBuscarClienteAsaas(Cliente $cliente)
    {
        try {
            // Tenta buscar o cliente pelo e-mail
            $response = Http::withHeaders([
                'access_token' => $this->apiKey,
            ])->get("{$this->baseUrl}/customers", [
                'email' => $cliente->email,
            ]);

            $customers = $response->json()['data'] ?? [];
            Log::alert($customers);
            // Se o cliente for encontrado, retorna o ID
            if (!empty($customers)) {
                return $customers[0]['id'];
            }
            Log::error('Cliente' . $cliente);
            // Se não for encontrado, cria um novo cliente na Asaas
            $createResponse = Http::withHeaders([
                'access_token' => $this->apiKey,
            ])->post("{$this->baseUrl}/customers", [
                'name' => $cliente->nome,
                'email' => $cliente->email,
                'phone' => $cliente->phone,
                'cpfCnpj' => $cliente->cpf_cnpj,
            ]);

            if ($createResponse->successful()) {
                return $createResponse->json()['id'];
            }

            Log::error('Erro ao criar cliente no Asaas: ' . $createResponse->body());
            return null;
        } catch (\Exception $e) {
            Log::error('Exceção ao buscar/criar cliente no Asaas: ' . $e->getMessage());
            return null;
        }
    }
}
