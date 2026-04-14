# GG Deals API

## O que é

A [GG Deals](https://gg.deals) é uma plataforma de agregação de preços de jogos. Sua API fornece dados de bundles ativos — usada aqui para detectar novos lançamentos de bundles/choices automaticamente e registrá-los no sistema.

Documentação oficial: https://gg.deals/api/bundles/

## Configuração

```env
# config/services.php → services.ggdeals.api_key
GG_DEALS_API_KEY=
```

A chave é passada como query param `key` em cada requisição.

## Endpoint utilizado

### `GET http://api.gg.deals/v1/bundles/active/`

Retorna todos os bundles ativos no momento da chamada.

**Query params:**
| Param | Valor |
|-------|-------|
| `key` | API key da conta |

**Timeout configurado:** 180 segundos (bundles podem ter muitos jogos).

**Estrutura da resposta:**
```json
{
  "data": {
    "bundles": [
      {
        "title": "Humble Choice - April 2025",
        "url": "https://www.humblebundle.com/...",
        "dateFrom": "2025-04-01",
        "dateTo": "2025-04-30",
        "tiers": [
          {
            "price": 12.00,
            "currency": "USD",
            "games": [
              { "title": "Game Name" },
              ...
            ]
          }
        ]
      },
      ...
    ]
  }
}
```

## Fluxo de sincronização (`BundleService::getBundlesFromAPI`)

```
APIService::getBundles()
  └── GET /v1/bundles/active/
        │
        ▼
BundleService::createBundlesFromAPI()
  │
  ├── Para cada bundle:
  │   ├── Determina tipo: "choice" se o título contém "Choice", senão "bundle"
  │   ├── Bundle::firstOrCreate(url) — URL é chave única (evita duplicatas)
  │   ├── Se for "choice" recém-criado → envia e-mail de alerta
  │   │
  │   ├── Pega o tier de maior preço (max dos tiers)
  │   ├── Converte preço para USD se necessário (via APIService::convertCurrency)
  │   ├── Calcula minimum_price_tf2 = price_dolar / tf2_price_dolar (tabela recursos)
  │   ├── Salva bundle com price_dolar e minimum_price_tf2
  │   │
  │   ├── Para cada jogo do tier:
  │   │   └── Game::firstOrCreate(name) — cria o jogo se não existir
  │   │
  │   ├── Associa jogos ao bundle via pivot bundle_games (syncWithoutDetaching)
  │   │
  │   └── Se bundle recém-criado → getBundleLaunchPrices()
  │         └── Chama Price Researcher API com os nomes dos jogos
  │               → recebe preço Gamivo no momento do lançamento
  │               → salva em bundle_games.bundle_launch_price
```

## Detalhes importantes

### Identificação do tipo
O campo `type` é determinado pelo título:
- Título contém "Choice" (case-insensitive) → `choice`
- Caso contrário → `bundle`

### Tier utilizado
Apenas o **tier de maior preço** (`max($api_bundle['tiers'])`) é processado. Os jogos e o preço do bundle são extraídos desse tier.

### Preço do bundle
- Se o tier já está em USD, usa diretamente.
- Caso contrário, converte para USD via `APIService::convertCurrency` (AwesomeAPI).
- O `minimum_price_tf2` é calculado dividindo o `price_dolar` pelo preço atual da TF2 Key em dólar (tabela `recursos`, nome `TF2`).

### Preço de lançamento dos jogos (`bundle_launch_price`)
Apenas para bundles **recém-criados** (primeira vez que aparecem na API). Chama o Price Researcher com os nomes dos jogos para obter o preço Gamivo no momento do lançamento. Esse preço é salvo na tabela pivot `bundle_games.bundle_launch_price` e é usado para calcular o lucro estimado da compra do bundle.

O preço vem no formato europeu (`0,09`) e é convertido para decimal (`0.09`) antes de salvar.

### Alerta de Choice novo
Quando um bundle do tipo `choice` é detectado pela primeira vez, um e-mail é disparado para `carcadeals@gmail.com` com o nome e URL do bundle.

### Idempotência
`Bundle::firstOrCreate(url)` e `Game::firstOrCreate(name)` garantem que rodar a sincronização múltiplas vezes não cria duplicatas. `syncWithoutDetaching` também garante que jogos já associados ao bundle não são removidos.

## Tabelas envolvidas

| Tabela | O que armazena |
|--------|---------------|
| `bundles` | Dados do bundle (nome, tipo, URL, datas, preço em USD, mínimo em TF2) |
| `games` | Catálogo de jogos (criados automaticamente se não existirem) |
| `bundle_games` | Pivot: quais jogos estão em quais bundles + `bundle_launch_price` |

## Quando é chamado

A sincronização é disparada manualmente ou via scheduler (a definir). Não há webhook — o sistema faz polling na API GG Deals.
