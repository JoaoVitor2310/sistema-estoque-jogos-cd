<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Traits\HttpResponses;
use Auth;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    use HttpResponses;

    public function redirectToGoogle()
    {
        return Socialite::driver('google')
            ->with(['prompt' => 'select_account']) // Força a seleção de conta
            ->redirect();
    }

    /**
     * Manipula o callback do Google após o login.
     */
    public function handleGoogleCallback()
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::updateOrCreate(
            ['google_id' => $googleUser->id],
            [
                'name' => $googleUser->name,
                'email' => $googleUser->email,
                'google_token' => $googleUser->token,
                'google_refresh_token' => $googleUser->refreshToken,
            ]
        );

        Auth::login($user);

        return redirect()->route('venda-chave-troca');
    }

    /**
     * Exibe informações do usuário logado ou uma mensagem de não autenticado.
     */
    public function logged()
    {
        $user = Auth::user();
        if ($user) {
            return response()->json($user); // Retorna os dados do usuário logado
        }

        return $this->error(401, 'Você não está autenticado. Tente novamente.');
    }

    /**
     * Faz o logout do usuário.
     */
    public function logout()
    {
        Auth::logout();
        return $this->response(200, 'Logout realizado com sucesso');
        // return redirect()->route('login'); // Redireciona para verificar se o usuário foi deslogado
    }
}
