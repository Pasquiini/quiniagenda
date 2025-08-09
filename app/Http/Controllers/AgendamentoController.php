<?php

namespace App\Http\Controllers;

use App\Jobs\SendLembreteAgendamentoEmail;
use App\Jobs\SendNotificacaoReagendamentoEmail;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Agendamento;
use App\Models\Cliente;
use App\Models\HorarioDisponivel;
use App\Models\HorarioExcecao;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AgendamentoController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = $user->agendamentos()->with(['cliente', 'servico'])->latest();

        // Filtro por cliente
        $query->when($request->filled('cliente_id'), function ($q) use ($request) {
            return $q->where('cliente_id', $request->cliente_id);
        });

        // Filtro por serviço
        $query->when($request->filled('servico_id'), function ($q) use ($request) {
            return $q->where('servico_id', $request->servico_id);
        });

        // Filtro por status
        $query->when($request->filled('status'), function ($q) use ($request) {
            return $q->where('status', $request->status);
        });

        return $query->get();
    }

    public function getPublicServices(int $userId)
    {
        $profissional = User::findOrFail($userId);
        $servicos = $profissional->servicos()->get();

        return response()->json($servicos);
    }

    public function storePublicBooking(int $userId, Request $request)
    {
        $profissional = User::findOrFail($userId);

        if ($profissional->plan && $profissional->plan->max_appointments !== null && $profissional->agendamentos()->count() >= $profissional->plan->max_appointments) {
            return response()->json(['message' => 'Profissional atingiu o limite de agendamentos.'], 403);
        }

        $validated = $request->validate([
            'cliente_name' => 'required|string|max:255',
            'cliente_email' => 'required|email|max:255',
            'cliente_phone' => 'required|string|max:20', // Adicionamos o telefone
            'servico_id' => ['required', 'exists:servicos,id', Rule::in($profissional->servicos()->pluck('id'))],
            'data_hora' => 'required|date_format:Y-m-d H:i:s', // Formato datetime
        ]);
        $formattedPhone = $this->formatPhoneNumberForDb($validated['cliente_phone']);

        $cliente = Cliente::firstOrCreate(
            ['email' => $validated['cliente_email']],
            ['nome' => $validated['cliente_name'], 'phone' => $formattedPhone, 'user_id' => $profissional->id]
        );

        $agendamentoStart = new \DateTime($validated['data_hora']);
        $servico = Servico::findOrFail($validated['servico_id']);
        $agendamentoEnd = (clone $agendamentoStart)->modify('+' . $servico->duration_minutes . ' minutes');

        // Lógica de verificação de disponibilidade
        $dayOfWeek = strtolower($agendamentoStart->format('l'));
        $horarioProfissional = $profissional->horariosDisponiveis()
            ->where('dia_da_semana', $dayOfWeek)
            ->first();

        if (!$horarioProfissional) {
            return response()->json(['message' => 'Profissional não trabalha neste dia.'], 409);
        }

        $agendamentoStartOnlyTime = new \DateTime($agendamentoStart->format('H:i'));
        $agendamentoEndOnlyTime = new \DateTime($agendamentoEnd->format('H:i'));
        $horarioInicioDisponivel = new \DateTime($horarioProfissional->hora_inicio);
        $horarioFimDisponivel = new \DateTime($horarioProfissional->hora_fim);

        if ($agendamentoStartOnlyTime < $horarioInicioDisponivel || $agendamentoEndOnlyTime > $horarioFimDisponivel) {
            return response()->json(['message' => 'O horário selecionado está fora do horário de trabalho do profissional.'], 409);
        }

        $existingAppointments = $profissional->agendamentos()
            ->whereDate('data_hora', $agendamentoStart->format('Y-m-d'))
            ->get();

        foreach ($existingAppointments as $appointment) {
            $existingAppointmentStart = new \DateTime($appointment->data_hora);
            $servicoExistente = Servico::findOrFail($appointment->servico_id);
            $existingAppointmentEnd = (clone $existingAppointmentStart)->modify('+' . $servicoExistente->duration_minutes . ' minutes');

            if ($agendamentoStart < $existingAppointmentEnd && $agendamentoEnd > $existingAppointmentStart) {
                return response()->json(['message' => 'O horário selecionado não está disponível.'], 409);
            }
        }

        $agendamento = $profissional->agendamentos()->create([
            'cliente_id' => $cliente->id,
            'servico_id' => $validated['servico_id'],
            'data_hora' => $agendamentoStart,
            'status' => 'pendente'
        ]);

        $agendamento->load('cliente', 'user', 'servico');


        SendWhatsappMessageJob::dispatch($agendamento);

        return response()->json([
            'message' => 'Agendamento criado com sucesso!',
            'agendamento' => $agendamento,
            'cliente_phone' => $cliente->phone,
        ], 201);
    }

    private function formatPhoneNumberForDb(string $phone): string
    {
        // Remove caracteres não numéricos
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        // Remove o '55' se já existir
        if (str_starts_with($cleaned, '55')) {
            $cleaned = substr($cleaned, 2);
        }

        // Se o número tiver 11 dígitos (com o 9), adiciona o 55
        // Ex: 11987654321 -> 5511987654321
        if (strlen($cleaned) === 11) {
            return '55' . $cleaned;
        }

        // Se o número tiver 10 dígitos (sem o 9, ex: 1188887777), adiciona o 55 e o 9
        // Ex: 1188887777 -> 5511988887777
        if (strlen($cleaned) === 10) {
            $ddd = substr($cleaned, 0, 2);
            $numero = substr($cleaned, 2);
            return '55' . $ddd . '9' . $numero;
        }

        return $cleaned; // Retorna o número limpo caso não se encaixe nos padrões
    }

    public function getAvailability(int $userId, Request $request)
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'servico_id' => 'required|exists:servicos,id',
        ]);

        $profissional = User::findOrFail($userId);
        $date = new \DateTime($request->date);
        $dayOfWeek = strtolower($date->format('l'));

        $servico = Servico::findOrFail($request->servico_id);
        $slotDuration = $servico->duration_minutes * 60;

        // Obtém a regra semanal para o dia
        $horarioProfissional = $profissional->horariosDisponiveis()
            ->where('dia_da_semana', $dayOfWeek)
            ->first();

        if (!$horarioProfissional) {
            return response()->json(['message' => 'Profissional não trabalha neste dia.'], 404);
        }

        $startTime = strtotime($horarioProfissional->hora_inicio);
        $endTime = strtotime($horarioProfissional->hora_fim);

        // Obtém os agendamentos existentes
        $existingAppointments = $profissional->agendamentos()
            ->whereDate('data_hora', $date)
            ->get();

        // Obtém os horários de exceção
        $horarioExcecoes = $profissional->horarioExcecoes()
            ->where('date', $date->format('Y-m-d'))
            ->get();

        $availableSlots = [];
        $currentSlot = $startTime;
        while ($currentSlot + $slotDuration <= $endTime) {
            $slotEnd = $currentSlot + $slotDuration;
            $slotIsAvailable = true;

            // Verifica se o slot se sobrepõe a um horário de exceção
            foreach ($horarioExcecoes as $excecao) {
                $excecaoStart = strtotime($excecao->start_time);
                $excecaoEnd = strtotime($excecao->end_time);

                if (($currentSlot >= $excecaoStart && $currentSlot < $excecaoEnd) ||
                    ($slotEnd > $excecaoStart && $slotEnd <= $excecaoEnd) ||
                    ($excecaoStart >= $currentSlot && $excecaoStart < $slotEnd)
                ) {
                    $slotIsAvailable = false;
                    break;
                }
            }

            // Verifica se o slot se sobrepõe a um agendamento existente
            if ($slotIsAvailable) {
                foreach ($existingAppointments as $appointment) {
                    $appointmentStart = strtotime($appointment->data_hora);
                    $appointmentEnd = $appointmentStart + ($appointment->servico->duration_minutes * 60);

                    if (($currentSlot >= $appointmentStart && $currentSlot < $appointmentEnd) ||
                        ($slotEnd > $appointmentStart && $slotEnd <= $appointmentEnd) ||
                        ($appointmentStart >= $currentSlot && $appointmentStart < $slotEnd)
                    ) {
                        $slotIsAvailable = false;
                        break;
                    }
                }
            }

            if ($slotIsAvailable) {
                $availableSlots[] = date('H:i', $currentSlot);
            }
            $currentSlot += $slotDuration;
        }

        return response()->json([
            'available_slots' => $availableSlots,
            'profissional_name' => $profissional->name,
        ]);
    }
    public function getMonthlyAvailability(int $userId, int $year, int $month, int $servicoId)
    {
        $profissional = User::findOrFail($userId);
        $servico = Servico::findOrFail($servicoId);
        $slotDuration = $servico->duration_minutes * 60;

        $startDate = Carbon::create($year, $month, 1);
        $endDate = $startDate->copy()->endOfMonth();
        $availableDays = [];

        for ($date = $startDate; $date->lte($endDate); $date->addDay()) {
            $dayOfWeek = strtolower($date->format('l'));

            // Verifica se existe uma exceção para a data específica
            $horarioExcecao = $profissional->horarioExcecoes()
                ->where('date', $date->toDateString())
                ->first();

            $startTime = null;
            $endTime = null;

            // Lógica CORRIGIDA para lidar com as exceções
            if ($horarioExcecao) {
                // Se a exceção tem horários nulos, o dia está bloqueado. Pula para o próximo dia.
                if ($horarioExcecao->start_time === null && $horarioExcecao->end_time === null) {
                    continue;
                }
                // Se a exceção tem horários definidos, usa esses horários
                $startTime = strtotime($horarioExcecao->start_time);
                $endTime = strtotime($horarioExcecao->end_time);
            } else {
                // Se não houver exceção, usa a regra geral
                $horarioProfissional = $profissional->horariosDisponiveis()
                    ->where('dia_da_semana', $dayOfWeek)
                    ->first();
                if (!$horarioProfissional) {
                    continue;
                }
                $startTime = strtotime($horarioProfissional->hora_inicio);
                $endTime = strtotime($horarioProfissional->hora_fim);
            }

            // Garante que os horários de início e fim foram definidos antes de continuar
            if (!$startTime || !$endTime) {
                continue;
            }

            // ... (o restante da lógica para verificar se há pelo menos um slot disponível no dia) ...
            $existingAppointments = $profissional->agendamentos()
                ->whereDate('data_hora', $date)
                ->get();

            $hasAvailableSlot = false;
            for ($currentSlot = $startTime; $currentSlot + $slotDuration <= $endTime; $currentSlot += $slotDuration) {
                $slotEnd = $currentSlot + $slotDuration;
                $slotIsAvailable = true;

                foreach ($existingAppointments as $appointment) {
                    $appointmentStart = strtotime($appointment->data_hora);
                    $appointmentEnd = $appointmentStart + ($appointment->servico->duration_minutes * 60);

                    if (($currentSlot >= $appointmentStart && $currentSlot < $appointmentEnd) ||
                        ($slotEnd > $appointmentStart && $slotEnd <= $appointmentEnd) ||
                        ($appointmentStart >= $currentSlot && $appointmentStart < $slotEnd)
                    ) {
                        $slotIsAvailable = false;
                        break;
                    }
                }

                if ($slotIsAvailable) {
                    $hasAvailableSlot = true;
                    break;
                }
            }

            if ($hasAvailableSlot) {
                $availableDays[] = $date->toDateString();
            }
        }

        return response()->json($availableDays);
    }


    public function store(Request $request)
    {
        $user = Auth::user();

        // Verifica se o usuário tem um plano e se o limite de agendamentos foi atingido
        if ($user->plan && $user->plan->max_appointments !== null && $user->agendamentos()->count() >= $user->plan->max_appointments) {
            return response()->json(['message' => 'Limite de agendamentos atingido.'], 403);
        }

        $validated = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id', Rule::in(Auth::user()->clientes()->pluck('id'))],
            'servico_id' => ['required', 'exists:servicos,id', Rule::in(Auth::user()->servicos()->pluck('id'))],
            'data_hora' => 'required|date',
        ]);

        // Define o status padrão como 'pendente' se não for enviado
        $validated['status'] = 'pendente';

        $isAvailable = HorarioDisponivel::disponivelParaAgendamento($validated['data_hora'])->exists();

        if (!$isAvailable) {
            return response()->json(['message' => 'O horário selecionado não está disponível.'], 409);
        }

        $agendamento = $user->agendamentos()->create($validated);

        // Dispara o Job de WhatsApp para enviar a notificação
        SendWhatsappMessageJob::dispatch($agendamento);

        return response()->json(['message' => 'Agendamento criado com sucesso!', 'agendamento' => $agendamento], 201);
    }

    public function update(Request $request, Agendamento $agendamento)
    {
        if (Auth::id() !== $agendamento->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $validated = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id', Rule::in(Auth::user()->clientes()->pluck('id'))],
            'servico_id' => ['required', 'exists:servicos,id', Rule::in(Auth::user()->servicos()->pluck('id'))],
            'data_hora' => 'required|date',
            'status' => 'required|in:pendente,confirmado,reagendado,cancelado',
        ]);

        // Verifica se o status ou a data_hora foram alterados
        $shouldSendWhatsapp = $request->input('status') !== $agendamento->status || $request->input('data_hora') !== $agendamento->data_hora;

        // Atualiza o agendamento
        $agendamento->update($validated);

        // Dispara o Job se houver uma mudança no status ou na data_hora
        if ($shouldSendWhatsapp) {
            SendWhatsappMessageJob::dispatch($agendamento);
        }

        return response()->json(['message' => 'Agendamento atualizado com sucesso!', 'agendamento' => $agendamento]);
    }

    public function destroy(Agendamento $agendamento)
    {
        if (Auth::id() !== $agendamento->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }
        $agendamento->delete();
        return response()->json(['message' => 'Agendamento excluído com sucesso!']);
    }

    public function show(Agendamento $agendamento)
    {
        // Verifica se o usuário autenticado é o dono do agendamento
        if (Auth::id() !== $agendamento->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        // Carrega os relacionamentos do agendamento (cliente e serviço)
        $agendamento->load('cliente', 'servico');

        // Retorna o agendamento em formato JSON
        return response()->json($agendamento);
    }
}
