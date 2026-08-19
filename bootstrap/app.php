<?php

use App\Http\Middleware\CheckAdmin;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\RequireTeam;
use App\Models\Asset;
use App\Models\Bundle;
use App\Models\Fee;
use App\Models\Game;
use App\Models\Key;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->validateCsrfTokens(except: ['*']);

        // Autorização roda ANTES do route model binding.
        //
        // Na ordem padrão o binding vem primeiro, e aí um visitante distingue
        // "registro existe" (403) de "não existe" (404) só variando o id da URL
        // — um oráculo de existência em rota que ele nem deveria alcançar. Com a
        // prioridade invertida, quem não passa no gate leva 403 nos dois casos e
        // não aprende nada sobre o que há no banco.
        foreach ([CheckAdmin::class, CheckPermission::class, RequireTeam::class] as $guard) {
            $middleware->prependToPriorityList(
                before: SubstituteBindings::class,
                prepend: $guard,
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Route model binding que não acha o registro responde no mesmo envelope
        // do HttpResponses (statusCode/message/errors/data), e não no formato
        // padrão do Laravel: os controllers devolviam esse envelope quando ainda
        // faziam o find() à mão, e o frontend lê `data.message`/`data.errors`.
        //
        // Registrado em NotFoundHttpException, e não em ModelNotFoundException,
        // porque o Laravel converte a segunda na primeira em prepareException()
        // ANTES de consultar os callbacks de render — um callback tipado na
        // original nunca dispara. A exceção anterior é o que distingue "binding
        // não achou o registro" de "URL não existe", que é outro assunto.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $modelNotFound = $e->getPrevious();

            if (! $modelNotFound instanceof ModelNotFoundException || ! $request->expectsJson()) {
                return null;
            }

            $messages = [
                Asset::class => 'Recurso não encontrado',
                Bundle::class => 'Bundle não encontrado',
                Fee::class => 'Taxa não encontrada',
                Game::class => 'Jogo não encontrado',
                Key::class => 'Key não encontrada',
            ];

            return response()->json([
                'statusCode' => 404,
                'message' => $messages[$modelNotFound->getModel()] ?? 'Registro não encontrado',
                'errors' => [],
                'data' => [],
            ], 404);
        });
    })->create();
