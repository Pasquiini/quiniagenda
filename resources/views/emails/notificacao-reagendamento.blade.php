<p>Olá, {{ $agendamento->cliente->nome }}!</p>
<p>O seu agendamento para o serviço de {{ $agendamento->servico->nome }} foi reagendado.</p>
<p>Nova data e Hora: {{ $agendamento->data_hora }}</p>
<p>Obrigado!</p>
