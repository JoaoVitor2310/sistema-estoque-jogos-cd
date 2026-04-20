<?php

namespace App\Traits;
use Illuminate\Support\MessageBag;
use Illuminate\Database\Eloquent\Model;

trait HttpResponses{
    public function response(string|int $code, string $message = "", mixed $data = []): \Illuminate\Http\JsonResponse
    {
        return response()->json(["statusCode" => $code, "message" => $message, "data" => $data], $code);
    }
    
    public function error(string|int $code, string $message = "", mixed $errors = [], mixed $data = []): \Illuminate\Http\JsonResponse
    {
        return response()->json(["statusCode" => $code, "message" => $message, "errors" => $errors, "data" => $data], $code);
    }
    
}