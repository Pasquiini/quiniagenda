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
use App\Services\AsaasService;
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
    private function tlv($id, $value)
    {
        $len = str_pad(strlen($value), 2, '0', STR_PAD_LEFT); // ASCII only
        return $id . $len . $value;
    }

    private function crc16($payload)
    {
        $poly = 0x1021;
        $crc = 0xFFFF;
        foreach (unpack('C*', $payload) as $b) {
            $crc ^= ($b << 8);
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ $poly) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    // mantém só ASCII, tira acentos e limita tamanho
    private function normalizePixText(string $s, int $maxLen, bool $upper = true): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); // remove acentos
        $s = preg_replace('/[^A-Za-z0-9 .,-]/', '', $s ?? ''); // apenas caracteres seguros
        $s = trim($s);
        if ($upper) $s = strtoupper($s);
        return substr($s, 0, $maxLen);
    }

    private function gerarPixPayload($pixKey, $amount, $description, $txid, $merchantName, $merchantCity)
    {
        // Normaliza campos
        $merchantName  = $this->normalizePixText($merchantName ?: 'NA', 25, true);
        $merchantCity  = $this->normalizePixText($merchantCity ?: 'NA', 15, true);
        $description   = $this->normalizePixText($description ?: '', 99, false);
        $txid          = $this->normalizePixText($txid ?: 'TX', 25, false);
        $pixKey        = trim($pixKey); // pode ter @, +, etc. (fica no ASCII)

        // 26 - Merchant Account Info (BR Code)
        $mai = $this->tlv('00', 'br.gov.bcb.pix')
            . $this->tlv('01', $pixKey)
            . ($description !== '' ? $this->tlv('02', $description) : '');

        // 62 - Additional Data Field (TXID)
        $adf = $this->tlv('05', $txid);

        // Payload base (estático -> 11)
        $payload =
            $this->tlv('00', '01') .                 // Payload Format Indicator
            $this->tlv('01', '11') .                 // Point of Initiation Method (estático)
            $this->tlv('26', $mai) .                 // Merchant Account Info
            $this->tlv('52', '0000') .               // MCC
            $this->tlv('53', '986') .                // BRL
            ($amount !== '' ? $this->tlv('54', $amount) : '') .
            $this->tlv('58', 'BR') .                 // País
            $this->tlv('59', $merchantName) .        // Nome recebedor
            $this->tlv('60', $merchantCity) .        // Cidade
            $this->tlv('62', $adf);                  // Dados adicionais (TXID)

        // CRC16 (campo 63)
        $payloadSemCRC = $payload . '63' . '04';
        $crc = $this->crc16($payloadSemCRC);

        return $payloadSemCRC . $crc;
    }

    public function storePublicBooking(int $userId, Request $request, AsaasService $asaasService)
    {
        $profissional = User::findOrFail($userId);

        if ($profissional->plan && $profissional->plan->max_appointments !== null && $profissional->agendamentos()->count() >= $profissional->plan->max_appointments) {
            return response()->json(['message' => 'Profissional atingiu o limite de agendamentos.'], 403);
        }

        $validated = $request->validate([
            'cliente_name' => 'required|string|max:255',
            'cliente_email' => 'required|email|max:255',
            'cliente_phone' => 'required|string|max:20',
            'cliente_cpf_cnpj' => 'nullable|string|max:20',
            'servico_id' => ['required', 'exists:servicos,id', Rule::in($profissional->servicos()->pluck('id'))],
            'data_hora' => 'required|date_format:Y-m-d H:i:s',
            'payment_option' => 'required|string|in:online,presencial'
        ]);
        $formattedPhone = $this->formatPhoneNumberForDb($validated['cliente_phone']);

        $cliente = Cliente::updateOrCreate(
            ['email' => $validated['cliente_email'], 'user_id' => $profissional->id],
            [
                'nome' => $validated['cliente_name'],
                'phone' => $validated['cliente_phone'],
                'cpf_cnpj' => $validated['cliente_cpf_cnpj'],
            ]
        );
        $agendamentoStart = new \DateTime($validated['data_hora']);
        $servico = Servico::findOrFail($validated['servico_id']);
        $agendamentoEnd = (clone $agendamentoStart)->modify('+' . $servico->duration_minutes . ' minutes');

        // Lógica de verificação de disponibilidade (mantida)
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
            ->where('status', 'confirmado')
            ->get();

        foreach ($existingAppointments as $appointment) {
            $existingAppointmentStart = new \DateTime($appointment->data_hora);
            $servicoExistente = Servico::findOrFail($appointment->servico_id);
            $existingAppointmentEnd = (clone $existingAppointmentStart)->modify('+' . $servicoExistente->duration_minutes . ' minutes');

            if ($agendamentoStart < $existingAppointmentEnd && $agendamentoEnd > $existingAppointmentStart) {
                return response()->json(['message' => 'O horário selecionado não está disponível.'], 409);
            }
        }

        // Fim da lógica de verificação de disponibilidade

        // Determina o status inicial do pagamento com base na opção do cliente
        $initialPaymentStatus = $validated['payment_option'] === 'online' ? 'Aguardando Pagamento' : 'Pendente';

        $agendamento = $profissional->agendamentos()->create([
            'cliente_id' => $cliente->id,
            'servico_id' => $validated['servico_id'],
            'data_hora' => $agendamentoStart,
            'status' => 'pendente',
            'payment_status' => $initialPaymentStatus,
            'payment_option' => $validated['payment_option'],
            'payment_amount' => $servico->valor,
        ]);

        $pixData = null;
        $pixConfig = $profissional->pixConfig;
        // Se o cliente escolheu pagamento online, gera a cobrança Pix
        if ($validated['payment_option'] === 'online') {
            $chavePix = $pixConfig->pix_key;
            $valor = number_format($servico->valor, 2, '.', '');           // "20.00"
            $descricao = "Agendamento de servico {$servico->nome}";        // sem acento
            $txid = substr(uniqid('AGD'), 0, 25);

            $merchantName = $profissional->fantasia ?: $profissional->name;
            $merchantCity = 'BRASIL'; // se tiver cidade no cadastro, use-a aqui

            $payload = $this->gerarPixPayload(
                $chavePix,
                $valor,
                $descricao,
                $txid,
                $merchantName,
                $merchantCity
            );

            // ATENÇÃO: use rawurlencode
            $qrCodeUrl = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . rawurlencode($payload);

            $pixData = [
                'encodedImage' => $qrCodeUrl,
                'payload' => $payload,
            ];

            $agendamento->update([
                'pix_txid'       => $txid,
                'pix_qrcode_url' => $qrCodeUrl,
                'pix_copia_cola' => $payload,
            ]);
        }

        $agendamento->load('cliente', 'user', 'servico');

        // SendWhatsappMessageJob::dispatch($agendamento);

        return response()->json([
            'message' => 'Agendamento criado com sucesso!',
            'agendamento' => $agendamento,
            'cliente_phone' => $cliente->phone,
            'pix' => $pixData
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
        $slotDuration = $servico->duration_minutes * 60; // Duração em segundos

        // Obtém a regra semanal para o dia
        $horarioProfissional = $profissional->horariosDisponiveis()
            ->where('dia_da_semana', $dayOfWeek)
            ->first();

        if (!$horarioProfissional) {
            return response()->json(['message' => 'Profissional não trabalha neste dia.'], 404);
        }

        $startTime = strtotime($horarioProfissional->hora_inicio);
        $endTime = strtotime($horarioProfissional->hora_fim);

        // Obtém os agendamentos existentes com status 'confirmado'
        $existingAppointments = $profissional->agendamentos()
            ->whereDate('data_hora', $date)
            ->where('status', 'confirmado')
            ->get();

        // Obtém os horários de exceção
        $horarioExcecoes = $profissional->horarioExcecoes()
            ->where('date', $date->format('Y-m-d'))
            ->get();

        $availableSlots = [];
        $currentSlot = $startTime;

        while ($currentSlot + $slotDuration <= $endTime) {
            $slotIsAvailable = true;

            // Verifica se o slot se sobrepõe a um horário de exceção
            foreach ($horarioExcecoes as $excecao) {
                $excecaoStart = strtotime($excecao->start_time);
                $excecaoEnd = strtotime($excecao->end_time);

                if (($currentSlot < $excecaoEnd && ($currentSlot + $slotDuration) > $excecaoStart)) {
                    $slotIsAvailable = false;
                    break;
                }
            }

            // Verifica se o slot se sobrepõe a um agendamento existente
            if ($slotIsAvailable) {
                foreach ($existingAppointments as $appointment) {
                    $appointmentStart = strtotime($appointment->data_hora);
                    $appointmentEnd = $appointmentStart + ($appointment->servico->duration_minutes * 60);

                    if (($currentSlot < $appointmentEnd && ($currentSlot + $slotDuration) > $appointmentStart)) {
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
        // SendWhatsappMessageJob::dispatch($agendamento);

        return response()->json(['message' => 'Agendamento criado com sucesso!', 'agendamento' => $agendamento], 201);
    }

    public function update(Request $request, Agendamento $agendamento)
    {
        if (Auth::id() !== $agendamento->user_id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $validated = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'servico_id' => ['required', 'exists:servicos,id', Rule::in(Auth::user()->servicos()->pluck('id'))],
            'data_hora' => 'required|date',
            'status' => 'required|in:pendente,confirmado,reagendado,cancelado',
            'payment_status' => 'nullable|string|in:Pendente,Aguardando Pagamento Online,Pago Presencial,Pago Online', // NOVO: Validação
            'payment_method' => 'nullable|string|in:Dinheiro,Cartao,Pix Presencial,Pix Online', // NOVO: Validação
        ]);

        // ATUALIZAÇÃO: Adicionamos os novos campos ao array de atualização
        $agendamento->update($validated);

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

    public function markAsPaid(Request $request, int $agendamentoId)
    {
        $agendamento = Agendamento::with(['user'])->findOrFail($agendamentoId);

        if ($request->user()->id !== $agendamento->user->id) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $validated = $request->validate([
            'payment_method' => 'required|string|in:Dinheiro,Cartao,Pix Presencial,Pix Online', // NOVO: Adicionamos Pix Online
        ]);
        $paymentStatus = ($validated['payment_method'] === 'Pix Online') ? 'Pago Online' : 'Pago Presencial';
        $agendamento->update([
            'status' => 'Confirmado',
            'payment_status' => $paymentStatus, // Se o pagamento for online, o status deve ser "Pago Online"
            'payment_method' => $validated['payment_method'],
        ]);

        return response()->json([
            'message' => 'Pagamento confirmado com sucesso!',
            'agendamento' => $agendamento,
        ], 200);
    }
}
