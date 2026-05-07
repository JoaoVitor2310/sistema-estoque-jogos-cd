<?php

namespace App\Domain\Assets;

/**
 * Regras de alerta para ativos de troca (ex: TF2 key).
 */
final class AssetAlert
{
    /**
     * Variação mínima no preço do dólar que dispara o alerta por e-mail.
     * Se abs(preçoAtual - preçoArmazenado) >= este valor, o alerta é enviado.
     */
    public const DOLLAR_PRICE_VARIATION_THRESHOLD = 0.20;
}
