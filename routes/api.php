<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\AgendamentoController;
use App\Http\Controllers\AsaasWebhookController;
use App\Http\Controllers\AvaliacaoLinkController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceiroController;
use App\Http\Controllers\HistoricoClienteController;
use App\Http\Controllers\HorarioDisponivelController;
use App\Http\Controllers\HorarioExcecaoController;
use App\Http\Controllers\OrcamentoController;
use App\Http\Controllers\PixController;
use App\Http\Controllers\PublicOrcamentoController;
use App\Http\Controllers\ServicoController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\StyleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Rotas públicas (não precisam de autenticação)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/orcamentos/{uuid}', [PublicOrcamentoController::class, 'show']);
Route::post('/orcamentos/{uuid}/approve', [PublicOrcamentoController::class, 'approve']);
Route::post('/stripe/webhook', [PlanController::class, 'handleWebhook']);
Route::get('/booking/{userId}/services', [AgendamentoController::class, 'getPublicServices']);
Route::get('/booking/{userId}/availability', [AgendamentoController::class, 'getAvailability']);
Route::post('/booking/{userId}/create', [AgendamentoController::class, 'storePublicBooking']);
Route::get('/booking/{userId}/availability/monthly/{year}/{month}/{servicoId}', [AgendamentoController::class, 'getMonthlyAvailability']);
Route::post('/pix/webhook', [PixController::class, 'webhook']); // coloque um middleware para assinar/validar
Route::get('/agendamentos/{agendamento}/payment-status', [PixController::class, 'paymentStatus']);
Route::get('professional/{userId}/has-pix', [AuthController::class, 'hasPix']);
Route::get('/style/{userId}', [StyleController::class, 'showPublic']);
Route::post('/billing-portal', [PlanController::class, 'redirectToBillingPortal'])->middleware('auth:api');
Route::get('/stripe-config', [StripeController::class, 'config']);

// Rotas protegidas (precisam de autenticação JWT)
Route::middleware('auth:api')->get('/subscription-details', [PlanController::class, 'getSubscriptionDetails']);

// Rotas para planos
Route::get('/plans', [PlanController::class, 'index']);
Route::middleware('auth:api')->group(function () {
    // Rotas de Autenticação
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);

    Route::post('/plans/subscribe', [PlanController::class, 'subscribe']);
    Route::get('/get-subscription-details', [PlanController::class, 'getSubscriptionDetails']);
    Route::post('plans/cancel-plan', [PlanController::class, 'cancelPlan'])->middleware('auth:api');

    Route::get('/statistics', [OrcamentoController::class, 'getStatistics']);
    Route::get('/orcamentos', [OrcamentoController::class, 'index']);
    Route::get('/orcamentos/{orcamento}', [OrcamentoController::class, 'show']);
    Route::post('/orcamentos', [OrcamentoController::class, 'store']);
    Route::put('/orcamentos/{orcamento}', [OrcamentoController::class, 'update']);
    Route::delete('/orcamentos/{orcamento}', [OrcamentoController::class, 'destroy']);
    Route::post('/orcamentos/{orcamento}/faturar', [OrcamentoController::class, 'faturar']);
    Route::get('/orcamentos/{orcamento}/pdf', [OrcamentoController::class, 'generatePdf']); // Adicione esta linha

    Route::get('/agendamentos', [AgendamentoController::class, 'index']);
    Route::post('/agendamentos', [AgendamentoController::class, 'store']);
    Route::get('/agendamentos/{agendamento}', [AgendamentoController::class, 'show']);
    Route::put('/agendamentos/{agendamento}', [AgendamentoController::class, 'update']);
    Route::delete('/agendamentos/{agendamento}', [AgendamentoController::class, 'destroy']);
    Route::get('/horarios-disponiveis', [HorarioDisponivelController::class, 'index']);
    Route::post('/horarios-disponiveis', [HorarioDisponivelController::class, 'store']);

    Route::get('/clientes', [ClienteController::class, 'index']);
    Route::post('/clientes', [ClienteController::class, 'store']);
    Route::get('/clientes/{cliente}', [ClienteController::class, 'show']);
    Route::put('/clientes/{cliente}', [ClienteController::class, 'update']);
    Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy']);

    Route::get('/dashboard-data', [DashboardController::class, 'index']);

    Route::get('/servicos', [ServicoController::class, 'index']);
    Route::post('/servicos', [ServicoController::class, 'store']);
    Route::put('/servicos/{servico}', [ServicoController::class, 'update']);
    Route::delete('/servicos/{servico}', [ServicoController::class, 'destroy']);

    Route::get('/horario-excecoes', [HorarioExcecaoController::class, 'index']);
    Route::post('/horario-excecoes', [HorarioExcecaoController::class, 'store']);
    Route::delete('/horario-excecoes/{horarioExcecao}', [HorarioExcecaoController::class, 'destroy']);
    Route::get('/financeiro/resumo', [FinanceiroController::class, 'getResumo']);
    Route::get('/financeiro/pendencias', [FinanceiroController::class, 'getPendencias']);
    Route::get('/pix-config', [PixController::class, 'show']);
    Route::post('/pix-config', [PixController::class, 'store']);
    Route::delete('/pix-config', [PixController::class, 'destroy']);
    Route::post('/agendamentos/{agendamentoId}/mark-as-paid', [AgendamentoController::class, 'markAsPaid']);
    Route::post('/style', [StyleController::class, 'store']);
    // Rota para buscar a estilização
    Route::get('/style', [StyleController::class, 'show']);
    Route::apiResource('avaliacoes', AvaliacaoLinkController::class);
    Route::get('/{cliente}/historico', [HistoricoClienteController::class, 'index']);
    Route::post('/{cliente}/historico', [HistoricoClienteController::class, 'store']);
    Route::get('/profissional/regras-agendamento', [AuthController::class, 'getRegrasAgendamento']);
    Route::put('/profissional/regras-agendamento', [AuthController::class, 'updateRegrasAgendamento']);
});
