<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expõe apenas os preços mínimo e máximo da API Gamivo para uma key.
 * Usado pelo endpoint searchByIdGamivo (consulta de preços vigentes).
 */
class KeyGamivoMinMaxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'min_api' => $this->min_api,
            'max_api' => $this->max_api,
        ];
    }
}
