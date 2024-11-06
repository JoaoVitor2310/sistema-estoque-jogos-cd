<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuthorizedUserRequest;
use App\Models\AuthorizedUsers;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuthorizedUsersController extends Controller
{
    use HttpResponses;

    public function index(Request $request)
    {
        $limit = $request->query('limit', 100);  // Valor padrão de 100 para buscar tudo
        $offset = $request->query('offset', 0);  // Valor padrão de 0 para procurar desde o primeiro

        // Busca os registros utilizando limit e offset
        $emails = AuthorizedUsers::orderBy('id', 'asc')->limit($limit)->offset($offset)->get();

        is_object($emails) ? $emails = $emails->toArray() : $emails; // Garante que sempre será um array, mesmo que tenha só um elemento

        //  return $this->response(200, 'Emails encontrados com sucesso.', $emails);

        return Inertia::render('Acesso', [
            'emails' => $emails,
        ]);
    }

    /**
     * Store a newly created email in storage.
     */
    public function store(Request $request)
    {
        $data = $request->all();

        try {
            $created = AuthorizedUsers::create($data);
            if ($created) {
                return $this->response(201, 'Usuário cadastrado com sucesso', $created);
            }

            return $this->error(400, 'Algo inesperado aconteceu, tente novamente.');
        } catch (\Exception $e) {
            \Log::error($e);

            // Return a JSON response with the error message
            return $this->error(500, 'Erro interno ao cadastrar usuário novo.', [$e->getMessage()]);
        }
    }

    public function destroy(string $id)
    {
        $email = AuthorizedUsers::select('*')->where('id', $id)->first();
        if (!$email)
            return $this->error(404, 'Usuário não encontrado');


        $result = AuthorizedUsers::where('id', $id)->delete();
        if (!$result)
            return $this->error(500, 'Erro interno ao deletar usuário');

        return $this->response(200, 'Usuário deletado com sucesso', $email);
    }

    public function destroyArray(Request $request)
    {
        $items = $request->input('items');
        if (!$items)
            return $this->error(404, 'Usuários não enviados', ['errors' => 'Usuários não enviados']);

        foreach ($items as $item) {

            $item = AuthorizedUsers::select('*')->where('id', $item['id'])->first();
            if (!$item)
                return $this->error(404, 'Usuário não encontrada');

            $result = AuthorizedUsers::where('id', $item['id'])->delete();
            if (!$result)
                return $this->error(500, 'Erro interno ao deletar usuário');
        }

        return $this->response(200, 'Usuários deletados com sucesso', $items);
    }

    public function update(AuthorizedUserRequest $request, string $id)
    {
        $item = AuthorizedUsers::select('*')->where('id', $id)->first();
        if (!$item)
            return $this->error(404, 'Usuário não encontrado');

        $data = $request->validated();

        $result = AuthorizedUsers::where('id', $id)->update($data);

        if (!$result)
            return $this->error(500, 'Erro interno ao atualizar taxa');

        return $this->response(200, 'Usuário atualizado com sucesso', $data);
    }
}
