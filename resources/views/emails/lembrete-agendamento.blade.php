<p>Olá, {{ $agendamento->cliente->nome }}!</p>
<p>Este é um lembrete do seu agendamento para o serviço de {{ $agendamento->servico->nome }}.</p>
<p>Data e Hora: {{ $agendamento->data_hora }}</p>
<p>Obrigado!</p>
