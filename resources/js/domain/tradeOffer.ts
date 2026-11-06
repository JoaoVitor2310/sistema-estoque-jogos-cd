// As contas de oferta da aba Trades, sem nada de tela.
//
// São a projeção client-side de regras cuja fonte da verdade é o Domain PHP: a
// tela precisa recalcular a cada tecla, sem ida ao servidor. Mudou lá, muda
// aqui — cada função diz o seu par, e tests/Fixtures/trade-offer-cases.json é
// lido pelas duas suítes, então mudar de um lado só quebra a do outro.

// Espelho de App\Domain\Enums\PurchaseChannel — de quem as keys foram compradas.
export type PurchaseChannel = 'supplier_trade' | 'bundle_store' | 'gamivo';

// Espelho de App\Domain\Enums\TradeCurrency — a moeda em que a trade é paga.
export type TradeCurrency = 'tf2' | 'eur' | 'usd';

// Preço de 1 TF2 key em cada moeda de dinheiro. Zero é "ainda não cadastrado".
export interface Tf2Prices {
  eur: number;
  usd: number;
}

// Taxas da Gamivo, como MarketplaceFee (PHP).
export interface MarketplaceFees {
  percentLow: number;
  fixedLow: number;
  percentHigh: number;
  fixedHigh: number;
}

// Espelho de MinimumMarginPolicy::initialMarginRules() (PHP).
export interface MarginRules {
  lowCostThreshold: number;
  highCostThreshold: number;
  veryHighCostThreshold: number;
  lowCostMargin: number;
  defaultMargin: number;
  highCostMargin: number;
  veryHighCostMargin: number;
  bundleStoreMargin: number;
}

export interface ProfitShortfall {
  profit: number;
  required: number;
}

// Espelho das constantes privadas de IncomeCalculator (PHP).
const MICRO_THRESHOLD = 0.28;
const MICRO_FIXED_FEE = 0.11;
const TIER_THRESHOLD = 8.0;

// Tolerância de ponto flutuante: um tier que bate a margem exata não é "abaixo".
const MARGIN_EPSILON = 1e-6;

// Espelho de OfferCalculator::MIN_CASH_OFFER e MIN_TF2_OFFER: o supplier nunca
// lê "0,00" ao lado de um jogo.
const MIN_OFFER_PRICE = 0.01;

/** Projeção de IncomeCalculator::forGamivo: o que sobra da venda, em euro. */
export function netIncome(marketPrice: number, fees: MarketplaceFees): number {
  if (marketPrice < MICRO_THRESHOLD) return marketPrice - MICRO_FIXED_FEE;
  if (marketPrice < TIER_THRESHOLD) return marketPrice * (1 - fees.percentLow) - fees.fixedLow;
  return marketPrice * (1 - fees.percentHigh) - fees.fixedHigh;
}

/** Projeção de OfferCalculator::tf2Offer: quantas TF2 ofertar para o lucro pedido. */
export function tf2Offer(income: number, profitPct: number, tf2EuroPrice: number): number {
  if (tf2EuroPrice <= 0) return 0;
  return income / (1 + profitPct / 100) / tf2EuroPrice;
}

/**
 * Projeção de MinimumMarginPolicy::initialMargin, em porcentagem. Só a margem
 * inicial entra: o tempo de estoque não existe ainda, a key será comprada agora.
 *
 * `cost` é em **euro**: as faixas são em euro, em qualquer moeda de trade.
 */
export function requiredMarginPct(cost: number, channel: PurchaseChannel, rules: MarginRules): number {
  if (channel === 'bundle_store') return rules.bundleStoreMargin * 100;
  if (cost > rules.veryHighCostThreshold) return rules.veryHighCostMargin * 100;
  if (cost > rules.highCostThreshold) return rules.highCostMargin * 100;
  if (cost < rules.lowCostThreshold) return rules.lowCostMargin * 100;
  return rules.defaultMargin * 100;
}

interface OfferedKey {
  income: number;
  tf2Value: number;
  tf2EuroPrice: number;
}

/** O lucro de pagar `tf2Value` TF2 por uma key que rende `income`; nulo sem como calcular. */
export function impliedProfitPct({ income, tf2Value, tf2EuroPrice }: OfferedKey): number | null {
  if (tf2Value <= 0 || tf2EuroPrice <= 0 || income <= 0) return null;
  return (income / (tf2Value * tf2EuroPrice) - 1) * 100;
}

/**
 * Se pagar `tf2Value` TF2 pela key der menos lucro que o min_api praticaria
 * para esse custo, devolve o lucro e o mínimo; senão null.
 *
 * Recebe sempre a oferta em TF2 e o preço em euro, em qualquer moeda de trade:
 * as faixas de custo são em euro.
 */
export function profitShortfall(
  key: OfferedKey & { channel: PurchaseChannel; rules: MarginRules },
): ProfitShortfall | null {
  const profit = impliedProfitPct(key);
  if (profit === null) return null;

  const required = requiredMarginPct(key.tf2Value * key.tf2EuroPrice, key.channel, key.rules);

  return profit < required - MARGIN_EPSILON ? { profit, required } : null;
}

/** Projeção de KeyCalculationService::getTf2Price: quanto vale 1 TF2 na moeda. */
export function tf2PriceIn(currency: TradeCurrency, prices: Tf2Prices): number {
  if (currency === 'tf2') return 1;
  return currency === 'eur' ? prices.eur : prices.usd;
}

/**
 * A cotação da TF2 que falta para mostrar uma oferta nesta moeda; nula se não
 * falta nenhuma. O euro vem primeiro, como em TradeOfferService: toda oferta
 * parte dele, seja qual for a moeda da trade.
 */
export function missingQuote(currency: TradeCurrency, prices: Tf2Prices): Exclude<TradeCurrency, 'tf2'> | null {
  if (prices.eur <= 0) return 'eur';
  return currency === 'usd' && prices.usd <= 0 ? 'usd' : null;
}

/** Se dá para mostrar uma oferta nesta moeda. */
export function canConvertTo(currency: TradeCurrency, prices: Tf2Prices): boolean {
  return missingQuote(currency, prices) === null;
}

/**
 * Projeção do round($valor, 2) do PHP: ao centavo mais próximo, meio centavo
 * para longe do zero. As 15 casas significativas tiram o ruído binário antes de
 * arredondar: 8,325 é guardado como 8,3249999… e precisa dar 8,33.
 */
function roundToCents(value: number): number {
  const cents = Number((Math.abs(value) * 100).toPrecision(15));

  return (Math.sign(value) * Math.round(cents)) / 100;
}

/**
 * Projeção de SupplierOfferLine::priceIn: o valor que o supplier lê, ao centavo
 * mais próximo e nunca abaixo do piso. Oferta que não é positiva fica como está:
 * o jogo não paga as taxas, e a tela mostra isso.
 */
export function offerPriceIn(tf2Value: number, currency: TradeCurrency, prices: Tf2Prices): number {
  const rounded = roundToCents(tf2Value * tf2PriceIn(currency, prices));

  return tf2Value > 0 ? Math.max(MIN_OFFER_PRICE, rounded) : rounded;
}

/** A cotação que falta para converter um valor entre as duas moedas; nula se não falta nenhuma. */
export function missingConversionQuote(
  from: TradeCurrency,
  to: TradeCurrency,
  prices: Tf2Prices,
): Exclude<TradeCurrency, 'tf2'> | null {
  const missing = [from, to].find(currency => tf2PriceIn(currency, prices) <= 0);

  return (missing ?? null) as Exclude<TradeCurrency, 'tf2'> | null;
}

/**
 * Projeção de OfferCalculator::convertAmount: o valor na outra moeda, pela razão
 * entre as cotações da TF2. Um valor positivo nunca vira zero, e sem valor
 * positivo não há o que converter.
 */
export function convertAmount(amount: number, from: TradeCurrency, to: TradeCurrency, prices: Tf2Prices): number {
  if (amount <= 0) return amount;

  const converted = (amount * tf2PriceIn(to, prices)) / tf2PriceIn(from, prices);

  return Math.max(MIN_OFFER_PRICE, roundToCents(converted));
}

/** O total lido: a soma dos valores já arredondados, para bater com as linhas. */
export function offerTotal(values: number[]): number {
  return Math.round(values.reduce((sum, value) => sum + value, 0) * 100) / 100;
}

/** O inverso de `offerPriceIn`, sem arredondar: o valor digitado, em TF2. */
export function tf2FromPrice(price: number, currency: TradeCurrency, prices: Tf2Prices): number {
  return price / tf2PriceIn(currency, prices);
}

/**
 * O income em euro na moeda em que a tela o mostra: dólar numa trade em USD, e
 * euro nas outras. A taxa é a razão entre as cotações da TF2, a mesma base da
 * oferta, para os dois andarem juntos. Nulo sem cotação para converter.
 */
export function incomeIn(income: number, currency: TradeCurrency, prices: Tf2Prices): number | null {
  if (currency !== 'usd') return income;
  return canConvertTo('usd', prices) ? income * (prices.usd / prices.eur) : null;
}
