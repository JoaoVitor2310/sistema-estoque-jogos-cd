<?php

namespace App\Services;

use App\Http\Helpers\Formulas;
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
  public function __construct(protected Formulas $formulas)
  {
    //
  }

  /**
   * Calculate minimun and maximum (without fees) that will be used to decrease/increase the price of the game on Gamivo.
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

  /**
   * Calcula fórmulas de lucro e classificações
   */
  public function calculateFormulas($game, $somatorioIncomes, $isEdit = false)
  {
    if (!$isEdit) {
      $game['valorPagoIndividual'] = $this->formulas->calcValorPagoIndividual(
        $game['qtdTF2'],
        $somatorioIncomes,
        $game['incomeSimulado']
      );

      $game['lucroRS'] = $this->formulas->calcLucroReal(
        $game['incomeSimulado'],
        $game['valorPagoIndividual']
      );

      $game['lucroPercentual'] = $this->formulas->calcLucroPercentual(
        $game['lucroRS'],
        $game['valorPagoIndividual']
      );
    }

    $game['lucroVendaRS'] = $this->formulas->calcLucroVendaReal(
      $game['valorVendido'],
      $game['valorPagoIndividual']
    );

    $game['lucroVendaPercentual'] = $this->formulas->calcLucroVendaPercentual(
      $game['lucroVendaRS'],
      $game['valorPagoIndividual']
    );

    $game['randomClassificationG2A'] = $this->formulas->classificacaoRandomG2A(
      $game['precoJogo'],
      $game['notaMetacritic']
    );

    $game['randomClassificationKinguin'] = $this->formulas->classificacaoRandomKinguin(
      $game['precoJogo'],
      $game['notaMetacritic']
    );

    return $game;
  }

  /**
   * Calcula as fórmulas iniciais (preço venda, income simulado, income real)
   */
  public function calculateFirstFormulas($games)
  {
    $somatorioIncomes = 0;
    foreach ($games as &$game) {
      $game['precoVenda'] = $this->formulas->calcPrecoVenda(
        $game['tipo_formato_id'],
        $game['id_plataforma'],
        $game['precoCliente']
      );

      $game['incomeSimulado'] = $this->formulas->calcIncomeSimulado(
        $game['tipo_formato_id'],
        $game['id_plataforma'],
        $game['precoCliente'],
        $game['precoVenda']
      );

      $game['incomeReal'] = $this->formulas->calcIncomeReal(
        $game['tipo_formato_id'],
        $game['id_plataforma'],
        $game['precoCliente'],
        $game['precoVenda'],
        $game['leiloes'],
        $game['quantidade']
      );

      $somatorioIncomes += $game['incomeSimulado'];
    }

    return [
      'games' => $games,
      'somatorioIncomes' => $somatorioIncomes
    ];
  }
}
