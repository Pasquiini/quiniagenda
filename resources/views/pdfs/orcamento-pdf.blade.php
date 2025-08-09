<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orçamento #{{ $orcamento->id }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; font-size: 14px; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { color: #007bff; font-size: 24px; }
        .details, .items, .footer { margin-bottom: 20px; }
        .details h3, .items h3, .footer h3 { color: #555; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .text-right { text-align: right; }
        .status-badge { display: inline-block; padding: 5px 10px; border-radius: 12px; color: #fff; font-weight: bold; }
        .status-badge.pendente { background-color: #ffc107; }
        .status-badge.aprovado { background-color: #28a745; }
        .status-badge.recusado { background-color: #dc3545; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ORÇAMENTO #{{ $orcamento->id }}</h1>
        <p>Emitido em: {{ $orcamento->created_at->format('d/m/Y') }}</p>
    </div>

    <div class="details">
        <h3>Detalhes do Cliente</h3>
        <p><strong>Nome:</strong> {{ $orcamento->cliente->nome }}</p>
        <p><strong>E-mail:</strong> {{ $orcamento->cliente->email ?? 'Não informado' }}</p>
        <p><strong>Telefone:</strong> {{ $orcamento->cliente->telefone ?? 'Não informado' }}</p>
    </div>

    <div class="items">
        <h3>Itens do Orçamento</h3>
        <table>
            <thead>
                <tr>
                    <th>Descrição</th>
                    <th>Quantidade</th>
                    <th>Valor Unitário</th>
                    <th>Valor Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orcamento->itens as $item)
                <tr>
                    <td>{{ $item->descricao }}</td>
                    <td>{{ $item->quantidade }}</td>
                    <td>R$ {{ number_format($item->valor_unitario, 2, ',', '.') }}</td>
                    <td>R$ {{ number_format($item->quantidade * $item->valor_unitario, 2, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="footer">
        <h3>Resumo</h3>
        <p><strong>Valor Total do Orçamento:</strong> R$ {{ number_format($orcamento->valor, 2, ',', '.') }}</p>
        <p><strong>Status:</strong> <span class="status-badge {{ $orcamento->status }}">{{ ucfirst($orcamento->status) }}</span></p>
        @if ($orcamento->status === 'aprovado')
            <p><strong>Aprovado por:</strong> {{ $orcamento->aprovado_por }} em {{ $orcamento->aprovado_em->format('d/m/Y H:i') }}</p>
        @endif
    </div>
</body>
</html>
