<?php

namespace App\Http\Requests;

use App\Domain\Enums\TradeLineAuthority;
use App\Domain\Trades\TradeLineValue;
use App\UseCases\Trades\DTO\TradeLineDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Os campos de uma linha que o **supplier** preenche na entrega.
 *
 * Mesmas regras que [[TradeLineRequest]] aplica a esses campos — o rigor
 * segue o mesmo critério, o da digitação: `expires_at` chega como texto porque
 * nem todo prefixo de uma data válida é uma data válida (`02/`, `02/0`), e o
 * autosave dispara a cada tecla.
 *
 * Esta lista **não** é a barreira de autoridade: quem recusa coluna fora do
 * escopo do supplier é [[App\Domain\Enums\TradeLineAuthority]], no UseCase. Aqui
 * ela é só validação — acrescentar um campo neste arquivo não amplia o alcance
 * dele. Ver docs/adr/0008.
 */
class DeliveryLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'region' => ['nullable', 'string', 'max:255'],
            'bundle' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'string', 'max:10'],
            'key_code' => ['nullable', 'string', 'max:255'],
        ];

        // Recusadas, não ignoradas — mesmo critério que [[TradeLineRequest]]
        // aplica a `position`: aceitar em silêncio devolveria 200 a quem tentou
        // mudar o preço, fazendo a tentativa parecer bem-sucedida. A lista sai
        // da diferença entre as duas autoridades para não haver uma segunda
        // cópia dela para manter em dia.
        $outOfScope = array_diff(
            [...TradeLineAuthority::Team->writableColumns(), 'position'],
            TradeLineAuthority::Supplier->writableColumns(),
        );

        foreach ($outOfScope as $column) {
            $rules[$column] = ['prohibited'];
        }

        return $rules;
    }

    public function toDTO(): TradeLineDTO
    {
        $validated = $this->validated();

        // A validade chega no formato que o supplier lê — `mm/dd/aaaa`, porque
        // a página dele é em inglês. A conversão para ISO acontece aqui, na
        // única camada que sabe quem escreveu: adiante, `03/04` já não diz se
        // era março ou abril.
        if (array_key_exists('expires_at', $validated)) {
            $validated['expires_at'] = TradeLineValue::date(
                $validated['expires_at'],
                TradeLineAuthority::Supplier->dateFormat(),
            );
        }

        return TradeLineDTO::fromValidated($validated);
    }
}
