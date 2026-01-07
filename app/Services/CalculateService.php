<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Venda_chave_troca;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CalculateService
{
  /**
   * Create a new class instance.
   */
  public function __construct()
  {
    //
  }

  /**
   * Calcula o mínimo e o máximo(sem as taxas) que será usado para diminuir/subir o preço do jogo na Gamivo.
   * 
   * @param mixed $game
   */
  public function calculateMinMaxApi($game)
  {
    $minApiGamivo = 0;
    $maxApiGamivo = 100;

    // Minimo
    if ($game['valorPagoIndividual'] > 10) {
      $minApiGamivo = $game['valorPagoIndividual'] * 1.4;
    } elseif ($game['valorPagoIndividual'] > 4.6) {
      $minApiGamivo = $game['valorPagoIndividual'] * 1.5;
    } elseif ($game['valorPagoIndividual'] >= 4) {
      $minApiGamivo = $game['valorPagoIndividual'];
    } else { // < 4
      $minApiGamivo = $game['valorPagoIndividual'] * 1.6;
    }

    // Maximo
    if ($game['valorPagoIndividual'] < 1) {
      $maxApiGamivo = $game['valorPagoIndividual'] * 30;
    } else {
      $maxApiGamivo = $game['valorPagoIndividual'] * 8;
    }

    if ($game['precoCliente'] >= $maxApiGamivo) {
      $maxApiGamivo = $game['precoCliente'] * 8;
    }

    $minApiGamivo = $minApiGamivo <= 0 ? 0.02 : $minApiGamivo;
    $maxApiGamivo = $maxApiGamivo <= 0 ? 0.02 : $maxApiGamivo;

    $game['minApiGamivo'] = $minApiGamivo;
    $game['maxApiGamivo'] = $maxApiGamivo;

    return $game;
  }
}
