import { describe, expect, it } from 'vitest';
import {
  impliedProfitPct,
  netIncome,
  profitShortfall,
  requiredMarginPct,
  tf2Offer,
  type PurchaseChannel,
} from './tradeOffer';
import cases from '../../../tests/Fixtures/trade-offer-cases.json';

// O mesmo fixture é lido pelo Pest (TradeOfferProjectionTest): mudar uma regra de
// um lado só quebra a suíte do outro.
const { fees: FEES, marginRules: RULES, netIncome: incomeCases, tf2Offer: offerCases, requiredMarginPercent: marginCases } = cases;

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

describe('impliedProfitPct', () => {
  it('is the income over the cost', () => {
    // Pagar 2 TF2 a 1,00 € por uma key que rende 3,00 € é 50% de lucro
    expect(impliedProfitPct(3, 2, 1)).toBeCloseTo(50, 6);
  });

  it('has no answer without a value, a TF2 price or an income', () => {
    expect(impliedProfitPct(3, 0, 1)).toBeNull();
    expect(impliedProfitPct(3, 2, 0)).toBeNull();
    expect(impliedProfitPct(0, 2, 1)).toBeNull();
  });
});

describe('profitShortfall', () => {
  it('reports the profit and the minimum when the offer is below the margin', () => {
    // Custo de 2,00 € exige 50%; 3,00 / 2,50 dá só 20%
    const shortfall = profitShortfall(3, 2.5, 1, 'supplier_trade', RULES);

    expect(shortfall?.profit).toBeCloseTo(20, 6);
    expect(shortfall?.required).toBeCloseTo(50, 6);
  });

  it('accepts an offer exactly at the margin', () => {
    expect(profitShortfall(3, 2, 1, 'supplier_trade', RULES)).toBeNull();
  });

  it('accepts an offer above the margin', () => {
    expect(profitShortfall(4, 2, 1, 'supplier_trade', RULES)).toBeNull();
  });

  it('measures the cost tier in euro', () => {
    // 12 TF2 a 1,00 € são € 12: faixa de 45%. Com a TF2 a 0,50 €, € 6: faixa de 50%
    expect(profitShortfall(17, 12, 1, 'supplier_trade', RULES)?.required).toBeCloseTo(45, 6);
    expect(profitShortfall(8.5, 12, 0.5, 'supplier_trade', RULES)?.required).toBeCloseTo(50, 6);
  });

  it('has nothing to report without a value or an income', () => {
    expect(profitShortfall(3, 0, 1, 'supplier_trade', RULES)).toBeNull();
    expect(profitShortfall(-1, 2, 1, 'supplier_trade', RULES)).toBeNull();
  });
});
