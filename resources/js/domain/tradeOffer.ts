// As contas de oferta da aba Trades, sem nada de tela.
//
// São a projeção client-side de regras cuja fonte da verdade é o Domain PHP: a
// tela precisa recalcular a cada tecla, sem ida ao servidor. Mudou lá, muda
// aqui — cada função diz o seu par, e tests/Fixtures/trade-offer-cases.json é
// lido pelas duas suítes, então mudar de um lado só quebra a do outro.

// Espelho de App\Domain\Enums\PurchaseChannel — de quem as keys foram compradas.
export type PurchaseChannel = 'supplier_trade' | 'bundle_store' | 'gamivo';

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

/** O lucro de pagar `tf2Value` TF2 por uma key que rende `income`; nulo sem como calcular. */
export function impliedProfitPct(income: number, tf2Value: number, tf2EuroPrice: number): number | null {
  if (tf2Value <= 0 || tf2EuroPrice <= 0 || income <= 0) return null;
  return (income / (tf2Value * tf2EuroPrice) - 1) * 100;
}

/**
 * Se pagar `tf2Value` TF2 pela key der menos lucro que o min_api praticaria
 * para esse custo, devolve o lucro e o mínimo; senão null.
 */
export function profitShortfall(
  income: number,
  tf2Value: number,
  tf2EuroPrice: number,
  channel: PurchaseChannel,
  rules: MarginRules,
): ProfitShortfall | null {
  const profit = impliedProfitPct(income, tf2Value, tf2EuroPrice);
  if (profit === null) return null;

  const required = requiredMarginPct(tf2Value * tf2EuroPrice, channel, rules);

  return profit < required - MARGIN_EPSILON ? { profit, required } : null;
}
