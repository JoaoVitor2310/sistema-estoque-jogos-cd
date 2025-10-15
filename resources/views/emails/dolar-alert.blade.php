<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alerta de Dolar</title>
</head>
<body>
    <h1>Alerta de Dolar</h1>
    <p>O dolar variou mais que 0.20. Cuidado ao comprar mais chaves. </p>
    <p>Valor que estamos pagando pela chave TF2: {{ $tf2->preco_dolar }}</p>
    <p>Valor que pagaria atualmente: {{ $data['preco_dolar'] }}</p>
</body>
</html>