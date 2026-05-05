<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Evita que testes renderizem views com @vite() e falhem por ausência do manifest.
        // Em CI o build do frontend não existe — o que importa é a resposta do controller.
        $this->withoutVite();

        // Garante que qualquer chamada HTTP não mockada falhe explicitamente.
        // Testes que precisam de chamadas externas devem usar Http::fake() explicitamente.
        Http::preventStrayRequests();
    }
}
