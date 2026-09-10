<?php

namespace App\Models;

use App\Domain\Enums\TradeDeliveryState;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Trade extends Model
{
    use SoftDeletes;

    protected $table = 'trades';

    /**
     * `delivery_uuid`, `delivery_token` e `delivered_at` ficam **fora** de
     * propósito: quem os grava são os UseCases da entrega, por atribuição
     * explícita. São o endereço, o segredo e o marco da entrega — nada que
     * chegue de um payload deve alcançá-los por atribuição em massa.
     */
    protected $fillable = ['supplier_id', 'list_code', 'last_commented_at', 'title', 'date', 'message_sent', 'is_imported', 'tf2_qty', 'supplier_notes'];

    /**
     * O token nunca sai numa serialização automática. Quem o expõe é a projeção
     * da aba de Trades, coluna a coluna, e mais ninguém — a projeção da entrega
     * não o inclui.
     */
    protected $hidden = ['delivery_token'];

    protected $casts = [
        'message_sent' => 'boolean',
        'is_imported' => 'boolean',
        'last_commented_at' => 'datetime',
        'date' => 'date',
        'tf2_qty' => 'decimal:2',
        'delivered_at' => 'datetime',
        // Encriptado, não em hash: o código fica à vista na aba para a equipe
        // copiar, e isso exige poder lê-lo de volta (ver docs/adr/0008).
        'delivery_token' => 'encrypted',
    ];

    /**
     * O token da entrega, ou `null` quando o valor guardado não abre com a
     * `APP_KEY` corrente.
     *
     * O cast `encrypted` lança na **leitura**, e o token é lido para toda trade
     * listada: uma linha encriptada com outra chave — chave rotacionada, ou um
     * dump de um ambiente aberto em outro — derrubava a aba inteira com 500.
     * Aqui a falha fica contida na trade: ela aparece sem código, e as demais
     * continuam. Todo leitor do token passa por aqui; ninguém lê a propriedade
     * direto.
     */
    public function readableDeliveryToken(): ?string
    {
        try {
            return $this->delivery_token;
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Em que ponto da entrega esta trade está — derivado de `delivered_at` e
     * `is_imported`, sem coluna de status (ver [[App\Domain\Enums\TradeDeliveryState]]).
     */
    public function deliveryState(): TradeDeliveryState
    {
        return TradeDeliveryState::resolve($this->delivered_at, (bool) $this->is_imported);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function keys(): HasMany
    {
        return $this->hasMany(Key::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TradeLine::class)->orderBy('position');
    }
}
