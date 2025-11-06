<?php

namespace App\Http\Helpers;
use App\Models\Ranges_taxa_G2A;
use App\Models\Taxas;
use App\Models\Recursos;

class Formulas
{

    private $taxasModel, $gamivoPercentualMaior, $gamivoFixoMaior, $gamivoPercentualMenor, $gamivoFixoMenor;

    public function __construct()
    {
        $this->taxasModel = new Taxas();
        $this->gamivoPercentualMaior = $this->taxasModel->where('name', 'gamivoPercentualMaior')->first()->preco;
        $this->gamivoFixoMaior = $this->taxasModel->where('name', 'gamivoFixoMaior')->first()->preco;
        $this->gamivoPercentualMenor = $this->taxasModel->where('name', 'gamivoPercentualMenor')->first()->preco;
        $this->gamivoFixoMenor = $this->taxasModel->where('name', 'gamivoFixoMenor')->first()->preco;
    }

    function calcPrecoVenda($idFormato, $idPlataforma, $precoCliente)
    {
        // Verifica se o formato é "T"
        if ($idFormato === 7) {
            return $precoCliente;
        }

        // Verifica se a Plataforma é "Gamivo" ou "Kinguin"
        if ($idPlataforma === 3 || $idPlataforma === 4) {
            return $precoCliente;
        }

        // Busca todas as faixas de preços e taxas da tabela ranges_taxa_g2a
        $faixas = Ranges_taxa_G2A::all();
        // return $faixas;

        // Itera sobre as faixas para encontrar a correspondente ao preço do cliente
        foreach ($faixas as $faixa) {
            if ($precoCliente >= $faixa->minimo && $precoCliente <= $faixa->maximo) {
                return number_format($precoCliente / (1 + $faixa->taxa), 2, '.', '');
            }
        }

        // Retorna o preço original se não encontrar uma faixa correspondente
        return $precoCliente;
    }

    public function calcIncomeReal($idFormato, $idPlataforma, $precoCliente, $precoVenda, $leiloes, $quantidade) // iGUAL AO SIMULADO, MAS É PARA G2A
    {
        $result = 0;

        if ($idFormato == 7) { // Troca
            $result = $precoCliente;
        } else if ($idPlataforma == 2) { // G2A
            $result = $precoVenda * 0.898 - 0.4 - (0.15 * $leiloes / $quantidade);
        } else if ($idPlataforma == 3) { // Gamivo
            
            if ($precoCliente < 8) {
                $feePercentage = $this->gamivoPercentualMenor;
                $feeFixed = $this->gamivoFixoMenor;
            } else {
                $feePercentage = $this->gamivoPercentualMaior;
                $feeFixed = $this->gamivoFixoMaior;
            }

            $result = $precoCliente * (1 - $feePercentage) - $feeFixed;
        } else if ($idPlataforma == 4) { // Kinguin
            $result = ($precoCliente * 0.8771929) - 0.306;
        } else {
            $result = $precoCliente; // Valor padrão caso nenhuma condição seja atendida
        }

        return number_format($result, 2, '.', '');
    }

    public function calcIncomeSimulado($idFormato, $idPlataforma, $precoCliente, $precoVenda)
    {
        $result = 0;

        if ($idFormato == 7) { // Troca
            $result = $precoCliente;
        } else if ($idPlataforma == 3) { // Gamivo
            
            if ($precoCliente < 8) {
                $feePercentage = $this->gamivoPercentualMenor;
                $feeFixed = $this->gamivoFixoMenor;
            } else {
                $feePercentage = $this->gamivoPercentualMaior;
                $feeFixed = $this->gamivoFixoMaior;
            }

            $result = $precoCliente * (1 - $feePercentage) - $feeFixed;
        } else if ($idPlataforma == 2) { // G2A
            $result = $precoVenda * 0.898 - 0.55;
        } else { // Kinguin
            $result = $precoVenda + (-0.1228071 * $precoVenda) - 0.306;
        }

        return number_format($result, 2, '.', '');
    }

    public function calcValorPagoIndividual($qtdTF2, $somatorioIncomes, $primeiroIncome)
    {
        $recursoModel = new Recursos();

        $valorChaveEUR = $recursoModel->select('*')->where('name', 'TF2')->first()['preco_euro'];

        if ($somatorioIncomes == 0 || $primeiroIncome == 0) {
            return 0;
        }

        return number_format($qtdTF2 * $valorChaveEUR / $somatorioIncomes * $primeiroIncome, 2, '.', '');
        // return $valorChaveEUR;
    }

    function calcLucroReal($incomeSimulado, $valorPagoIndividual)
    {
        if (!empty($incomeSimulado)) {
            return number_format($incomeSimulado - $valorPagoIndividual, 2, '.', '');
        } else {
            return 0;
        }
    }

    function calcLucroPercentual($lucroRS, $valorPagoIndividual)
    {
        // Verifica se lucroRS não está vazio ou nulo
        if (!empty($lucroRS) && $valorPagoIndividual > 0) {
            return number_format(($lucroRS / $valorPagoIndividual) * 100, 2, '.', '');
        } else {
            return 0;
        }
    }

    function calcLucroVendaReal($valorVendido, $valorPagoIndividual)
    {
        if (!empty($valorVendido)) {
            return number_format($valorVendido - $valorPagoIndividual, 2, '.', '');
        } else {
            return 0;
        }
    }

    function calcLucroVendaPercentual($lucroVendaRS, $valorPagoIndividual)
    {
        if (!empty($lucroVendaRS) && $valorPagoIndividual > 0) {
            return number_format(($lucroVendaRS / $valorPagoIndividual) * 100, 2, '.', '');
        } else {
            return 0;
        }
    }

    public function classificacaoRandomG2A($precoJogo, $nota = 1)
    {
        if ($precoJogo >= 39.99 && $nota >= 80) {
            return "VIP";
        } elseif ($precoJogo >= 29.99 && $nota >= 80) {
            return "Diamond";
        } elseif ($precoJogo >= 24.99 && $nota >= 70) {
            return "Elite";
        } elseif ($precoJogo >= 19.99 && $nota >= 80) {
            return "Legendary";
        } elseif ($precoJogo >= 10 && $nota >= 70) {
            return "Gold";
        } elseif ($precoJogo >= 8 && $nota >= 75) {
            return "Premium";
        } elseif ($precoJogo < 8 && $precoJogo != 0) {
            return "Random";
        } elseif ($nota < 70 && $nota != 0) {
            return "Random";
        } else {
            return "";
        }
    }

    public function classificacaoRandomKinguin($precoJogo, $nota = 1)
    {
        if ($precoJogo >= 16.99 && $nota >= 80) {
            return "Deluxe";
        } elseif ($precoJogo >= 11.99 && $nota >= 75) {
            return "Gold";
        } elseif ($precoJogo >= 8.99 && $nota >= 70) {
            return "Premium";
        } elseif ($precoJogo >= 2.99 && $nota >= 50) {
            return "Random";
        } else {
            return "Nenhuma";
        }
    }
}
