<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrega recebida - Sistema de Estoque</title>
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
            background-color: #ede0fb;
            border: 1px solid #d3b8f5;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .alert-title {
            color: #4b0082;
            margin: 0;
            font-size: 18px;
        }
        .summary {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .summary th,
        .summary td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .summary th {
            background-color: #f2f2f2;
            width: 34%;
        }
        /* `!important` e cor repetida inline: cliente de e-mail costuma impor
           a própria cor de link, e azul sobre o roxo do botão não se lê. */
        .cta,
        .cta:link,
        .cta:visited,
        .cta:hover {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 20px;
            background-color: #8009EF;
            color: #ffffff !important;
            font-weight: bold;
            text-decoration: none;
            border-radius: 5px;
        }
        .notes {
            margin-top: 20px;
            padding: 12px;
            background-color: #f8f9fa;
            border-left: 4px solid #8009EF;
            white-space: pre-wrap;
        }
        .footer {
            margin-top: 30px;
            font-size: 12px;
            color: #777;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1 class="alert-title">📦 Um supplier terminou a entrega</h1>
    </div>

    <p>
        A entrega já está fechada para ele e esperando conferência. Enquanto ninguém
        importar, ela fica no topo da aba <strong>Abertas</strong>.
    </p>

    <table class="summary">
        <tr>
            <th>Trade</th>
            <td>{{ $trade->title ?: '—' }} (#{{ $trade->id }})</td>
        </tr>
        <tr>
            <th>Supplier</th>
            <td>{{ $trade->supplier?->name ?: ($trade->supplier?->url ?: '—') }}</td>
        </tr>
        <tr>
            <th>Keys preenchidas</th>
            <td>{{ $filledLines }} de {{ $totalLines }}</td>
        </tr>
        <tr>
            <th>TF2 acertadas</th>
            <td>{{ $trade->tf2_qty ?? '—' }}</td>
        </tr>
        <tr>
            <th>Entregue em</th>
            <td>{{ $trade->delivered_at?->format('d/m/Y H:i') }}</td>
        </tr>
    </table>

    @if ($trade->supplier_notes)
        <div class="notes"><strong>Recado dele:</strong><br>{{ $trade->supplier_notes }}</div>
    @endif

    <a class="cta" href="{{ $tradesUrl }}" style="display:inline-block;margin-top:20px;padding:12px 20px;background-color:#8009EF;color:#ffffff;font-weight:bold;text-decoration:none;border-radius:5px;">
        <span style="color:#ffffff;">Abrir a fila de conferência</span>
    </a>

    <div class="footer">
        As keys não vão neste e-mail de propósito — elas ficam na aba, onde o acesso é controlado.
    </div>
</body>
</html>
