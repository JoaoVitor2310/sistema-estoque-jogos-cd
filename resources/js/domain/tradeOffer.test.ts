import { describe, expect, it } from 'vitest';
import {
  canConvertTo,
  impliedProfitPct,
  incomeIn,
  missingQuote,
  netIncome,
  offerPriceIn,
  offerTotal,
  profitShortfall,
  requiredMarginPct,
  tf2FromPrice,
  tf2Offer,
  type PurchaseChannel,
  type Tf2Prices,
  type TradeCurrency,
} from './tradeOffer';
import cases from '../../../tests/Fixtures/trade-offer-cases.json';

// O mesmo fixture é lido pelo Pest (TradeOfferProjectionTest): mudar uma regra de
// um lado só quebra a suíte do outro.
const {
  fees: FEES,
  marginRules: RULES,
  netIncome: incomeCases,
  tf2Offer: offerCases,
  requiredMarginPercent: marginCases,
  offerPrice: priceCases,
} = cases;

const PRICES: Tf2Prices = { eur: 0.95, usd: 1.1 };

describe('netIncome', () => {
  it.each(incomeCases)('nets $expected from a market price of $marketPrice', ({ marketPrice, expected }) => {
    expect(netIncome(marketPrice, FEES)).toBeCloseTo(expected, 6);
  });
});

describe('tf2Offer', () => {
  it.each(offerCases)('offers $expected TF2 for $income at $profitPercent%', ({ income, profitPercent, tf2EuroPrice, expected }) => {
    expect(tf2Offer(income, profitPercent, tf2EuroPrice)).toBeCloseTo(expected, 6);
  });
});

describe('requiredMarginPct', () => {
  it.each(marginCases)('requires $expected% for a cost of $cost in $channel', ({ cost, channel, expected }) => {
    expect(requiredMarginPct(cost, channel as PurchaseChannel, RULES)).toBeCloseTo(expected, 6);
  });
});

describe('offerPriceIn', () => {
  it.each(priceCases)('reads $tf2Offer TF2 as $expected $currency', ({ tf2Offer: value, currency, tf2Price, expected }) => {
    const prices = { eur: tf2Price, usd: tf2Price };

    expect(offerPriceIn(value, currency as TradeCurrency, prices)).toBeCloseTo(expected, 6);
  });

  it('leaves an offer that is not positive as it is', () => {
    expect(offerPriceIn(0, 'eur', PRICES)).toBe(0);
    expect(offerPriceIn(-0.5, 'tf2', PRICES)).toBe(-0.5);
  });

  it('rounds half a cent away from zero on a negative offer too', () => {
    expect(offerPriceIn(-1.005, 'tf2', PRICES)).toBe(-1.01);
  });
});

describe('offerTotal', () => {
  it('sums the rounded values, without float noise', () => {
    expect(offerTotal([2.34, 3.18])).toBe(5.52);
    expect(offerTotal([0.1, 0.2])).toBe(0.3);
    expect(offerTotal([])).toBe(0);
  });
});

describe('tf2FromPrice', () => {
  it('turns a typed price back into TF2', () => {
    expect(tf2FromPrice(2.85, 'eur', PRICES)).toBeCloseTo(3, 6);
    expect(tf2FromPrice(3.3, 'usd', PRICES)).toBeCloseTo(3, 6);
    expect(tf2FromPrice(3, 'tf2', PRICES)).toBe(3);
  });

  it('round-trips a typed price to the same value', () => {
    expect(offerPriceIn(tf2FromPrice(5, 'usd', PRICES), 'usd', PRICES)).toBe(5);
  });
});

describe('missingQuote', () => {
  it('names nothing when the quotes the currency needs are there', () => {
    expect(missingQuote('tf2', PRICES)).toBeNull();
    expect(missingQuote('eur', PRICES)).toBeNull();
    expect(missingQuote('usd', PRICES)).toBeNull();
  });

  it('names the dollar only on a USD trade', () => {
    expect(missingQuote('usd', { eur: 0.95, usd: 0 })).toBe('usd');
    expect(missingQuote('tf2', { eur: 0.95, usd: 0 })).toBeNull();
    expect(missingQuote('eur', { eur: 0.95, usd: 0 })).toBeNull();
  });

  it('names the euro whatever the currency, since every offer starts from it', () => {
    expect(missingQuote('tf2', { eur: 0, usd: 1.1 })).toBe('eur');
    expect(missingQuote('usd', { eur: 0, usd: 1.1 })).toBe('eur');
    expect(missingQuote('usd', { eur: 0, usd: 0 })).toBe('eur');
  });
});

describe('canConvertTo', () => {
  it('needs the euro price, and the price of the currency', () => {
    expect(canConvertTo('tf2', PRICES)).toBe(true);
    expect(canConvertTo('usd', PRICES)).toBe(true);
    expect(canConvertTo('usd', { eur: 0.95, usd: 0 })).toBe(false);
    expect(canConvertTo('eur', { eur: 0, usd: 1.1 })).toBe(false);
    expect(canConvertTo('tf2', { eur: 0, usd: 1.1 })).toBe(false);
  });
});

describe('incomeIn', () => {
  it('stays in euro on TF2 and euro trades', () => {
    expect(incomeIn(3.98, 'tf2', PRICES)).toBe(3.98);
    expect(incomeIn(3.98, 'eur', PRICES)).toBe(3.98);
  });

  it('converts to dollar by the ratio of the TF2 prices', () => {
    expect(incomeIn(0.95, 'usd', PRICES)).toBeCloseTo(1.1, 6);
  });

  it('has no dollar income without the dollar price', () => {
    expect(incomeIn(3.98, 'usd', { eur: 0.95, usd: 0 })).toBeNull();
  });

  it('keeps the proportion between the income and the offer', () => {
    // A mesma oferta a 100% de lucro vale metade do income em qualquer moeda
    const offer = offerPriceIn(tf2Offer(10, 100, PRICES.eur), 'usd', PRICES);

    expect(incomeIn(10, 'usd', PRICES)! / offer).toBeCloseTo(2, 2);
  });
});

describe('impliedProfitPct', () => {
  it('is the income over the cost', () => {
    // Pagar 2 TF2 a 1,00 € por uma key que rende 3,00 € é 50% de lucro
    expect(impliedProfitPct({ income: 3, tf2Value: 2, tf2EuroPrice: 1 })).toBeCloseTo(50, 6);
  });

  it('has no answer without a value, a TF2 price or an income', () => {
    expect(impliedProfitPct({ income: 3, tf2Value: 0, tf2EuroPrice: 1 })).toBeNull();
    expect(impliedProfitPct({ income: 3, tf2Value: 2, tf2EuroPrice: 0 })).toBeNull();
    expect(impliedProfitPct({ income: 0, tf2Value: 2, tf2EuroPrice: 1 })).toBeNull();
  });
});

describe('profitShortfall', () => {
  const key = { tf2EuroPrice: 1, channel: 'supplier_trade', rules: RULES } as const;

  it('reports the profit and the minimum when the offer is below the margin', () => {
    // Custo de 2,50 € exige 50%; 3,00 / 2,50 dá só 20%
    const shortfall = profitShortfall({ ...key, income: 3, tf2Value: 2.5 });

    expect(shortfall?.profit).toBeCloseTo(20, 6);
    expect(shortfall?.required).toBeCloseTo(50, 6);
  });

  it('accepts an offer exactly at the margin', () => {
    expect(profitShortfall({ ...key, income: 3, tf2Value: 2 })).toBeNull();
  });

  it('accepts an offer above the margin', () => {
    expect(profitShortfall({ ...key, income: 4, tf2Value: 2 })).toBeNull();
  });

  it('measures the cost tier in euro', () => {
    // 12 TF2 a 1,00 € são € 12: faixa de 45%. Com a TF2 a 0,50 €, € 6: faixa de 50%
    expect(profitShortfall({ ...key, income: 17, tf2Value: 12 })?.required).toBeCloseTo(45, 6);
    expect(profitShortfall({ ...key, income: 8.5, tf2Value: 12, tf2EuroPrice: 0.5 })?.required).toBeCloseTo(50, 6);
  });

  it('has nothing to report without a value or an income', () => {
    expect(profitShortfall({ ...key, income: 3, tf2Value: 0 })).toBeNull();
    expect(profitShortfall({ ...key, income: -1, tf2Value: 2 })).toBeNull();
  });
});
