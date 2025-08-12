<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alerta de Expiração - Sistema de Estoque</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .alert-title {
            color: #721c24;
            margin: 0;
            font-size: 18px;
        }
        .games-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .games-table th,
        .games-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .games-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .games-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .urgent {
            background-color: #ffebee !important;
            color: #c62828;
            font-weight: bold;
        }
        .warning {
            background-color: #fff3e0 !important;
            color: #ef6c00;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2 class="alert-title">⚠️ Alerta de Expiração - Sistema de Estoque</h2>
        <p>Os seguintes jogos expirarão em até 14 dias:</p>
        <p><strong>Total de jogos:</strong> {{ $jogos->count() }}</p>
    </div>

    <table class="games-table">
        <thead>
            <tr>
                <th>Nome do Jogo</th>
                <th>Key</th>
                <th>Data de Expiração</th>
                <th>Dias Restantes</th>
                <th>Perfil/Origem</th>
            </tr>
        </thead>
        <tbody>
            @foreach($jogos as $jogo)
                @php
                    $diasRestantes = floor(\Carbon\Carbon::now()->diffInDays(\Carbon\Carbon::parse($jogo->dataExpiracao)));
                    $classeCor = '';
                    if ($diasRestantes <= 7) {
                        $classeCor = 'urgent';
                    } elseif ($diasRestantes <= 14) {
                        $classeCor = 'warning';
                    }
                @endphp
                <tr class="{{ $classeCor }}">
                    <td>{{ $jogo->nomeJogo ?? 'N/A' }}</td>
                    <td>{{ $jogo->chaveRecebida ?? 'N/A' }}</td>
                    <td>{{ $jogo->dataExpiracao ? \Carbon\Carbon::parse($jogo->dataExpiracao)->format('d/m/Y') : 'N/A' }}</td>
                    <td>
                        {{ $diasRestantes }} dia{{ $diasRestantes != 1 ? 's' : '' }}
                        @if($diasRestantes <= 3)
                            🚨
                        @elseif($diasRestantes <= 7)
                            ⚠️
                        @endif
                    </td>
                    <td>{{ $jogo->fornecedor->perfilOrigem ?? 'Não encontrado' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p><strong>Legenda:</strong></p>
        <p>🚨 = Expira em 7 dias ou menos (URGENTE)</p>
        <p>⚠️ = Expira em 14 dias ou menos (ATENÇÃO)</p>
        <br>
        <p>Este email foi enviado automaticamente pelo Sistema Estoque do Carca Deals.</p>
        <p>Data e hora do envio: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</p>
    </div>
</body>
</html>
