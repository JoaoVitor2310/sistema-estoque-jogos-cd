<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGameRequest;
use App\Http\Requests\StoreGameRequestArray;
use App\Models\Plataforma;
use App\Models\Tipo_formato;
use App\Models\Tipo_leilao;
use App\Models\Tipo_reclamacao;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use App\Models\Venda_chave_troca;
use App\Models\Fornecedor;
use App\Http\Helpers\Formulas;
use Inertia\Inertia;

class VendaChaveTrocaController extends Controller
{
    use HttpResponses;

    protected $formulas;
    /**
     * Display a listing of the resource.
     */
    public function __construct()
    {
        $this->formulas = new Formulas();
    }



    public function show(Request $request) // Renderiza a tela inicial
    {
        $limit = $request->query('limit', 100);  // Valor padrão de 100

        $games = Venda_chave_troca::with([
            'fornecedor',
            'tipoReclamacao',
            'tipoFormato',
            'leilaoG2A',
            'leilaoGamivo',
            'leilaoKinguin',
            'plataforma'
            // ])->orderBy('id', 'desc')->limit($limit)->offset($offset)->get();
        ])->orderBy('id', 'desc')->paginate($limit);

        // $totalGames = Venda_chave_troca::count();
        $totalGames = $games->total();  // O paginate já retorna o total de registros
        $tiposFormato = Tipo_formato::all();
        $tiposLeilao = Tipo_leilao::all();
        $plataformas = Plataforma::all();
        $tiposReclamacao = Tipo_reclamacao::all();


        // is_object($games) ? $games = $games->toArray() : $games; // Garante que sempre será um array, mesmo que tenha só um elemento


        // Se for a primeira requisição (renderizar a página com Inertia.js)
        return Inertia::render('VendaChaveTroca', [
            // 'games' => $games,
            'games' => $games->items(), // Retorna apenas os itens da página atual
            'totalGames' => $totalGames,
            'tiposFormato' => $tiposFormato,
            'tiposLeilao' => $tiposLeilao,
            'plataformas' => $plataformas,
            'tiposReclamacao' => $tiposReclamacao,
            'pagination' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
            ],
        ]);

        // return $this->response(200, 'Jogos encontrados com sucesso.', $jogos);
    }

    public function paginated(Request $request)// Não renderiza a tela inicial
    {
        $limit = $request->query('limit', 100);  // Valor padrão de 100
        // $offset = $request->query('offset', 0);  // Valor padrão de 0

        $games = Venda_chave_troca::with([
            'fornecedor',
            'tipoReclamacao',
            'tipoFormato',
            'leilaoG2A',
            'leilaoGamivo',
            'leilaoKinguin',
            'plataforma'
            // ])->orderBy('id', 'desc')->limit($limit)->offset($offset)->get();
        ])->orderBy('id', 'desc')->paginate($limit);

        // $totalGames = Venda_chave_troca::count();
        $totalGames = $games->total();  // O paginate já retorna o total de registros
        $tiposFormato = Tipo_formato::all();
        $tiposLeilao = Tipo_leilao::all();
        $plataformas = Plataforma::all();
        $tiposReclamacao = Tipo_reclamacao::all();


        // is_object($games) ? $games = $games->toArray() : $games; // Garante que sempre será um array, mesmo que tenha só um elemento


        return $this->response(200, 'Página de jogos atualizada com sucesso.', [
            'games' => $games,
            // 'games' => $games->items(), // Retorna apenas os itens da página atual
            'totalGames' => $totalGames,
            'tiposFormato' => $tiposFormato,
            'tiposLeilao' => $tiposLeilao,
            'plataformas' => $plataformas,
            'tiposReclamacao' => $tiposReclamacao,
            'pagination' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
            ],
        ]);


        // return $this->response(200, 'Jogos encontrados com sucesso.', $jogos);
    }

    public function search(Request $request)
    {
        $filters = $request->except('page'); // Filtra todos os campos, exceto 'page'

        // Iniciando a consulta
        $query = Venda_chave_troca::with([
            'fornecedor',
            'tipoReclamacao',
            'tipoFormato',
            'leilaoG2A',
            'leilaoGamivo',
            'leilaoKinguin',
            'plataforma'
        ]);

        // return $this->response(200, 'DEBUG.', $filters);
        foreach ($filters as $key => $value) {
            if ($value) {
                if (is_array($value)) {
                    $query->whereIn($key, $value);
                } else if (is_string($value)) {
                    $query->where($key, 'ILIKE', "%" . $value . "%");
                } else {
                    $query->where($key, $value);
                }
            }
        }

        // Paginação
        $limit = $filters['limit'] ?? 100;
        $games = $query->orderBy('id', 'desc')->paginate($limit);

        return $this->response(200, 'Pesquisa realizada com sucesso.', [
            'games' => $games,
            'totalGames' => $games->total(),
            'pagination' => [
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
                'per_page' => $games->perPage(),
            ],
        ]);
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreGameRequestArray $request)
    {
        $data = $request->validated();

        
        $resultFirstFormulas = $this->calculateFirstFormulas($data['games']);
        
        $data['games'] = $resultFirstFormulas['games'];
        $somatorioIncomes = $resultFirstFormulas['somatorioIncomes'];
        
        foreach ($data['games'] as $game) {
            $game['id_fornecedor'] = $this->criarAdicionarFornecedor($game['perfilOrigem'], $game['tipo_reclamacao_id']);
            
            // Calcula as fórmulas
            $game = $this->calculateFormulas($game, $somatorioIncomes);
            
            $repeatedGame = Venda_chave_troca::select('*')->where('chaveRecebida', $game['chaveRecebida'])->first();
            
            if ($repeatedGame) {
                $game['repetido'] = true;
            }
            
            // Função para identificar a plataforma do jogo
            $game['plataformaIdentificada'] = $this->identifyPlatform($game['chaveRecebida']);
            
            // return $this->response(200, 'DEBUG.', $game);
            try {
                $created = Venda_chave_troca::create($game);
                if ($created) {
                    $fullGame = Venda_chave_troca::select('*')->where('id', $created->id)->with([
                        'fornecedor',
                        'tipoReclamacao',
                        'tipoFormato',
                        'leilaoG2A',
                        'leilaoGamivo',
                        'leilaoKinguin',
                        'plataforma'
                        ])->first();

                    $fullGames[] = $fullGame;
                } else {
                    return $this->error(400, 'Algo deu errado!');

                }

            } catch (\Exception $e) {
                // Log the error
                \Log::error($e);

                return $this->error(500, 'Erro interno ao cadastrar novo jogo', [$e->getMessage()]);
            }
        }

        return $this->response(201, 'Jogos cadastrados com sucesso', $fullGames);

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreGameRequest $request, string $id)
    {
        $game = Venda_chave_troca::with('tipoReclamacao')->find($id);

        if (!$game)
            return $this->error(404, 'Jogo não encontrado');

        $updatedGame = $request->validated();

        if (!isset($updatedGame['qtdTF2'])) {
            $updatedGame['qtdTF2'] = $game['qtdTF2'];
        }


        // Calcula as fórmulas
        $resultFirstFormulas = $this->calculateFirstFormulas([$updatedGame]);
        $data = $resultFirstFormulas['games'];
        $somatorioIncomes = $resultFirstFormulas['somatorioIncomes'];
        $data = $this->calculateFormulas($data[0], $somatorioIncomes);



        // Lógica para fornecedores
        // return $this->error(500, 'debug', [$game['tipo_reclamacao']]);
        $data = $this->editarFornecedor($data, $game);
        // return response()->json($data);

        $result = Venda_chave_troca::where('id', $id)->update($data);

        if (!$result)
            return $this->error(500, 'Erro interno ao atualizar jogo');

        $game = Venda_chave_troca::select('*')->where('id', $id)->with([
            'fornecedor',
            'tipoReclamacao',
            'tipoFormato',
            'leilaoG2A',
            'leilaoGamivo',
            'leilaoKinguin',
            'plataforma'
        ])->first();

        return $this->response(200, 'Jogo atualizado com sucesso', $game);

        // return $this->response(200, 'Jogo atualizado com sucesso', $game);

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $game = Venda_chave_troca::select('*')->where('id', $id)->first();
        if (!$game)
            return $this->error(404, 'Jogo não encontrado');


        $result = Venda_chave_troca::where('id', $id)->delete();
        if (!$result)
            return $this->error(500, 'Erro interno ao deletar jogo');

        return $this->response(200, 'Jogo deletado com sucesso', $game);
    }

    public function destroyArray(Request $request)
    {
        $games = $request->input('games');
        if (!$games)
            return $this->error(404, 'Jogos não enviados', ['games' => 'Jogos não enviados']);
        // return $this->response(200, 'a', $jogos);
        foreach ($games as $game) {

            $item = Venda_chave_troca::select('*')->where('id', $game['id'])->first();
            if (!$item)
                return $this->error(404, 'Jogo não encontrado');

            $result = Venda_chave_troca::where('id', $game['id'])->delete();
            if (!$result)
                return $this->error(500, 'Erro interno ao deletar jogo');
        }

        return $this->response(200, 'Jogos deletados com sucesso', $games);
    }

    // Funções auxiliares

    private function editarFornecedor($data, $game): mixed
    { // data = enviado; game = cadastrado
        $fornecedorCadastrado = Fornecedor::select('*')->where('perfilOrigem', $game['perfilOrigem'])->first();
        $fornecedorEnviado = Fornecedor::select('*')->where('perfilOrigem', $data['perfilOrigem'])->first();
        if (!$fornecedorEnviado) { // Se não existe o fornecedor enviado, cria
            $data['id_fornecedor'] = $this->criarAdicionarFornecedor($data['perfilOrigem'], $data['tipo_reclamacao_id']);
            // Diminui uma reclamação do fornecedor cadastrado

            if ($fornecedorCadastrado->quantidade_reclamacoes > 0)
                $fornecedorCadastrado->where('perfilOrigem', $game['perfilOrigem'])->update(['quantidade_reclamacoes' => $fornecedorCadastrado->quantidade_reclamacoes - 1]);
        } else {

            if ($fornecedorEnviado['id'] != $fornecedorCadastrado['id']) { // Não é o mesmo fornecedor
                // Checar se tem reclamação no fornecedor enviado
                if ($data['tipo_reclamacao_id'] != 1) {
                    // Diminuir uma reclamação do fornedor cadastrado e adicionar para o enviado
                    if ($fornecedorCadastrado->quantidade_reclamacoes > 0)
                        $fornecedorCadastrado->where('id', $fornecedorCadastrado['id'])->update(['quantidade_reclamacoes' => $fornecedorCadastrado->quantidade_reclamacoes - 1]);
                    $fornecedorEnviado->where('id', $fornecedorEnviado['id'])->update(['quantidade_reclamacoes' => $fornecedorEnviado->quantidade_reclamacoes + 1]);
                }
            } else { // Se for o mesmo, verifica se mudou de true para false e retira um
                if ($data['tipo_reclamacao_id'] != 1 && $game->tipoReclamacao->id == 1) { // NÃO tinha reclamação e agora tem
                    // return $fornecedorEnviado['quantidade_reclamacoes'];
                    $fornecedorEnviado->where('id', $fornecedorEnviado['id'])->update(['quantidade_reclamacoes' => $fornecedorEnviado->quantidade_reclamacoes + 1]);
                } else { // Tinha reclamação e agora não tem
                    if ($fornecedorEnviado->quantidade_reclamacoes > 0)
                        $fornecedorEnviado->where('id', $fornecedorEnviado['id'])->update(['quantidade_reclamacoes' => $fornecedorEnviado->quantidade_reclamacoes - 1]);
                }
            }

            $data['id_fornecedor'] = $fornecedorEnviado['id'];
        }
        return $data;
    }

    private function criarAdicionarFornecedor($perfilOrigem, $reclamacao)
    {
        $fornecedor = Fornecedor::select('*')->where('perfilOrigem', $perfilOrigem)->first();

        if (!$fornecedor) { // Se não tiver o fornecedor, cria ele
            $newFornecedor['perfilOrigem'] = $perfilOrigem;
            if ($reclamacao != 1)
                $newFornecedor['quantidade_reclamacoes'] = 1; // Se tiver reclamação, adiciona +1
            $fornecedor = Fornecedor::create($newFornecedor);
            // return $this->error(400, $fornecedor);
        } else {
            // Existe o fornecedor, irá somar mais uma reclamação só se tiver mandado reclamação
            if ($reclamacao != 1) {
                $fornecedor->where('perfilOrigem', $perfilOrigem)->update(['quantidade_reclamacoes' => $fornecedor->quantidade_reclamacoes + 1]);
                // $fornecedor['quantidade_reclamacoes'] = $fornecedor->quantidade_reclamacoes;
            }
        }

        return $fornecedor->id;
    }

    private function calculateFirstFormulas($games)
    {
        $somatorioIncomes = 0;
        foreach ($games as &$game) {
            $game['precoVenda'] = $this->formulas->calcPrecoVenda($game['tipo_formato_id'], $game['id_plataforma'], $game['precoCliente']);
            $game['incomeSimulado'] = $this->formulas->calcIncomeSimulado($game['tipo_formato_id'], $game['id_plataforma'], $game['precoCliente'], $game['precoVenda']);
            $game['incomeReal'] = $this->formulas->calcIncomeReal($game['tipo_formato_id'], $game['id_plataforma'], $game['precoCliente'], $game['precoVenda'], $game['leiloes'], $game['quantidade']);
            $somatorioIncomes += $game['incomeSimulado'];
        }
        return ['games' => $games, 'somatorioIncomes' => $somatorioIncomes];
    }

    private function calculateFormulas($game, $somatorioIncomes)
    {

        $game['valorPagoIndividual'] = $this->formulas->calcValorPagoIndividual($game['qtdTF2'], $somatorioIncomes, $game['incomeSimulado']); // CONFERIR incomeSimulado primeiroIncome

        $game['lucroRS'] = $this->formulas->calcLucroReal($game['incomeSimulado'], $game['valorPagoIndividual']);

        $game['lucroPercentual'] = $this->formulas->calcLucroPercentual($game['lucroRS'], $game['valorPagoIndividual']);

        $game['randomClassificationG2A'] = $this->formulas->classificacaoRandomG2A($game['precoJogo'], $game['notaMetacritic']);

        $game['randomClassificationKinguin'] = $this->formulas->classificacaoRandomKinguin($game['precoJogo'], $game['notaMetacritic']);

        return $game;
    }

    private function identifyPlatform($chaveRecebida)
    {
        // Definição de padrões usando expressões regulares para identificar as plataformas
        $patterns = [
            'Steam' => '/^\w{5}-\w{5}-\w{5}$|^\w{5}-\w{5}-\w{5}-\w{5}-\w{5}$|^\w{15}\s\w{2}$/', // 12345-12345-12345
            'EA' => '/^\w{4}-\w{4}-\w{4}-\w{4}-\w{4}$/', // 1234-1234-1234-1234-1234
            'EA/Ubisoft' => '/^\w{4}-\w{4}-\w{4}-\w{4}$/', // 1234-1234-1234-1234
            'EGS' => '/^\w{5}-\w{5}-\w{5}-\w{5}$/', // 12345-12345-12345-12345
            'GOG' => '/^\w{18}$/', // 123456789012345678
            'XBOX' => '/^\w{5}-\w{5}-\w{5}-\w{5}-\w{5}$/', // 12345-12345-12345-12345-12345
            'PSN' => '/^\w{4}-\w{4}-\w{4}$/', // 1234-1234-1234
        ];

        foreach ($patterns as $platform => $pattern) {
            if (preg_match($pattern, $chaveRecebida)) {
                return $platform;
            }
        }

        return 'DESCONHECIDO';
    }
}