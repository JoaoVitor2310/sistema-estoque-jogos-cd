<?php

namespace App\Http\Controllers\Keys;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportKeysRequest;
use App\Traits\HttpResponses;
use App\UseCases\Keys\ImportKeysFromXlsxUseCase;

/**
 * Importação de keys via XLSX.
 * Responsabilidade: HTTP only — recebe o arquivo, delega ao UseCase, retorna response.
 */
class KeyImportController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly ImportKeysFromXlsxUseCase $importKeysUseCase,
    ) {}

    /**
     * Importa keys a partir de um arquivo Excel.
     */
    public function import(ImportKeysRequest $request)
    {
        $result = $this->importKeysUseCase->execute($request->file('file')->getRealPath());

        if (!$result['success']) {
            return $this->error(422, $result['message'], $result['errors']);
        }

        return $this->response(201, $result['message'], $result['data']);
    }

    /**
     * Download do arquivo de exemplo para importação.
     */
    public function downloadExample()
    {
        $filePath = public_path('assets/example/import_keys.xlsx');

        if (!file_exists($filePath)) {
            return $this->error(404, 'Arquivo de exemplo não encontrado');
        }

        return response()->download($filePath, 'exemplo-importacao-jogos.xlsx');
    }
}
