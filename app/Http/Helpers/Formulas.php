<?php 

namespace App\Http\Helpers;
use App\Models\Ranges_taxa_G2A;
use App\Models\Taxas;
use App\Models\Recursos;

class Formulas 
{

    private $taxasModel, $gamivoPercentualMaior4, $gamivoFixoMaior4, $gamivoPercentualMenor4, $gamivoFixoMenor4;

    public function __construct() {
        $this->taxasModel = new Taxas();
        $this->gamivoPercentualMaior4 = $this->taxasModel->where('name', 'gamivoPercentualMaior4')->first()->preco;
        $this->gamivoFixoMaior4 = $this->taxasModel->where('name', 'gamivoFixoMaior4')->first()->preco;
        $this->gamivoPercentualMenor4 = $this->taxasModel->where('name', 'gamivoPercentualMenor4')->first()->preco;
        $this->gamivoFixoMenor4 = $this->taxasModel->where('name', 'gamivoFixoMenor4')->first()->preco;
    }

    function calcPrecoVenda($idFormato, $idPlataforma, $precoCliente) {
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
                return round($precoCliente / (1 + $faixa->taxa), 2);
            }
        }
    
        // Retorna o preço original se não encontrar uma faixa correspondente
        return $precoCliente;
    }

    public function calcIncomeReal($idFormato, $idPlataforma, $precoCliente, $precoVenda, $leiloes, $quantidade) {
        $resultado = 0;
    
        if ($idFormato == 7) { // Troca
            $resultado = $precoCliente;
        } else if ($idPlataforma == 2) { // G2A
            $resultado = $precoVenda * 0.898 - 0.4 - (0.15 * $leiloes / $quantidade);
        } else if ($idPlataforma == 3) { // Gamivo
            if ($precoCliente < 4) {
                $resultado = ($precoCliente * $this->gamivoPercentualMenor4) - $this->gamivoFixoMenor4;
            } else {
                $resultado = ($precoCliente * $this->gamivoPercentualMaior4) - $this->gamivoFixoMaior4;
            }
        } else if ($idPlataforma == 4) { // Kinguin
            $resultado = ($precoCliente * 0.8771929) - 0.306;
        } else {
            $resultado = $precoCliente; // Valor padrão caso nenhuma condição seja atendida
        }
    
        return round($resultado, 2);
    } 

    public function calcIncomeSimulado($idFormato, $idPlataforma, $precoCliente, $precoVenda) {
        $resultado = 0;
    
        if ($idFormato == 7) { // Troca
            $resultado = $precoCliente;
        } else if ($idPlataforma == 3) { // Gamivo
            if ($precoCliente > 4) {
                $resultado = $precoVenda + (-$this->gamivoPercentualMaior4 * $precoVenda) - $this->gamivoFixoMaior4;
            } else {
                $resultado = $precoVenda - ($this->gamivoPercentualMenor4 * $precoVenda) - $this->gamivoFixoMenor4;
            }
        } else if ($idPlataforma == 2) { // G2A
            $resultado = $precoVenda * 0.898 - 0.55;
        } else { // Kinguin
            $resultado = $precoVenda + (-0.1228071 * $precoVenda) - 0.306;
        }
    
        return round($resultado, 2);
    }

    public function calcValorPagoIndividual($qtdTF2, $somatorioIncomes, $primeiroIncome){
        $recursoModel = new Recursos();

        $valorChaveEUR = $recursoModel->select('*')->where('name', 'TF2')->first()['preco_euro'];
        
        if($somatorioIncomes == 0 || $primeiroIncome == 0){
            return 0;
        }

        return $qtdTF2 * $valorChaveEUR / $somatorioIncomes * $primeiroIncome;
        // return $valorChaveEUR;
    }
    
    function calcLucroReal($incomeSimulado, $valorPagoIndividual) {
        // Verifica se incomeSimulado não está vazio ou nulo
        if (!empty($incomeSimulado)) {
            // Retorna a subtração entre incomeSimulado e valorPagoIndividual
            return round($incomeSimulado - $valorPagoIndividual, 2);
        } else {
            // Retorna uma string vazia se incomeSimulado estiver vazio
            return 0.00;
        }
    }

    // public function calcularLucroReal($vendido, $quantidade, $leiloes, $precoCliente, $valorPagoIndividual, $devolucoes){
    //     return $precoCliente * $vendido * 0.892 - (1.33 * $vendido + (0.57 / $quantidade) * $leiloes) - $valorPagoIndividual - $precoCliente * $devolucoes;
    // }
    function calcLucroPercentual($lucroRS, $valorPagoIndividual) {
        // Verifica se lucroRS não está vazio ou nulo
        if (!empty($lucroRS) && $valorPagoIndividual > 0) {
            return round(($lucroRS / $valorPagoIndividual) * 100, 2);
        } else {
            return 0;
        }
    }
    
    // public function calcularLucroPercentual($lucroRS, $valorPagoIndividual){
    //     return  $lucroRS /$valorPagoIndividual;
    // }
    public function classificacaoRandomG2A($precoJogo, $nota){
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

    public function classificacaoRandomKinguin($precoJogo, $nota){
        if (empty($precoJogo) || empty($nota)) {
            return "";
        } elseif ($precoJogo >= 16.99 && $nota >= 80) {
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
