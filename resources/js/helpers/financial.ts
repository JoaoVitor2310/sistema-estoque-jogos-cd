/**
 * Vocabulário do fechamento mensal — tipos, rótulos e os números derivados dos
 * movimentos.
 *
 * Mora fora da página porque a mesma leitura acontece em dois lugares: o mês em
 * aberto, na própria página, e o mês do histórico, no modal de detalhes. Rótulo
 * ou fórmula duplicada entre os dois é como as duas telas passam a discordar
 * sobre o mesmo mês.
 */

export type AccountType = 'principal' | 'tf2' | 'reinvestment' | 'emergency';
export type Numeric = string | number | null;

export interface Movement {
  id: number;
  group_id: string | null;
  account_type: AccountType;
  direction: 'credit' | 'debit';
  category: string;
  expense_category: string | null;
  income_category: string | null;
  amount: Numeric;
  description: string | null;
  occurred_at: string;
  quantity: Numeric;
  unit_price: Numeric;
  partner_slot: number | null;
  is_generated: boolean;
}

export interface FinancialMonth {
  id: number;
  year: number;
  month: number;
  status: 'draft' | 'closed';
  reinvestment_percent: Numeric;
  emergency_percent: Numeric;
  partner_one_share: Numeric;
  closed_at: string | null;
  movements?: Movement[];
}

export type Balances = Record<AccountType, number>;

export const MONTHS = [
  'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
  'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
];

export const CATEGORY_LABELS: Record<string, string> = {
  opening: 'Abertura',
  income: 'Entrada',
  expense: 'Saída',
  tf2_allocation: 'Verba de TF2',
  tf2_purchase: 'Compra de TF2',
  transfer: 'Transferência',
  partner_distribution: 'Saque de sócio',
};

export const ACCOUNTS: { label: string; value: AccountType }[] = [
  { label: 'Principal', value: 'principal' },
  { label: 'Verba de TF2', value: 'tf2' },
  { label: 'Reinvestimento', value: 'reinvestment' },
  { label: 'Emergência', value: 'emergency' },
];

// A TF2 tem painel dedicado (saldo + progresso da meta), então sai do grid
// genérico de saldos — mas continua uma conta como qualquer outra em ACCOUNTS
// (selects de conta, cálculo do total da empresa etc.).
export const BALANCE_GRID_ACCOUNTS = ACCOUNTS.filter((a) => a.value !== 'tf2');

export const EXPENSE_CATEGORIES = [
  { label: 'Compra de Jogo', value: 'game_purchase' },
  { label: 'Impostos', value: 'taxes' },
  { label: 'Assinaturas', value: 'subscriptions' },
  { label: 'Outros', value: 'other' },
];

export const INCOME_CATEGORIES = [
  { label: 'Saque Gamivo', value: 'gamivo_payout' },
  { label: 'Investimento externo', value: 'external_investment' },
  { label: 'Rendimentos', value: 'yield' },
  { label: 'Outros', value: 'other' },
];

export const accountLabel = (account: AccountType): string =>
  ACCOUNTS.find((a) => a.value === account)?.label ?? account;

export const subcategoryLabel = (movement: Movement): string => {
  if (movement.expense_category) {
    return EXPENSE_CATEGORIES.find((c) => c.value === movement.expense_category)?.label ?? movement.expense_category;
  }
  if (movement.income_category) {
    return INCOME_CATEGORIES.find((c) => c.value === movement.income_category)?.label ?? movement.income_category;
  }

  return '—';
};

export const brl = (value: Numeric): string =>
  value == null ? '—' : Number(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

export const monthLabel = (month: FinancialMonth): string => `${MONTHS[month.month - 1]}/${month.year}`;

export const signedAmount = (movement: Movement): string =>
  `${movement.direction === 'credit' ? '+' : '−'} ${brl(movement.amount)}`;

export const movementLabel = (movement: Movement): string => {
  const base = CATEGORY_LABELS[movement.category] ?? movement.category;

  return movement.partner_slot ? `${base} ${movement.partner_slot}` : base;
};

/**
 * Espelha `MovementDeletionPolicy` (PHP), que é quem recusa de verdade: linha
 * gerada pelo sistema e a abertura do mês não se apagam pela tela.
 */
export const isDeletable = (movement: Movement): boolean =>
  !movement.is_generated && movement.category !== 'opening';

/** Uma transferência vira duas linhas; apagar leva o par junto. */
export const groupSizeOf = (movements: Movement[], movement: Movement): number =>
  movement.group_id ? movements.filter((m) => m.group_id === movement.group_id).length : 1;

/** As porcentagens do mês são gravadas em fração (0.2 = 20%). */
export const percentOf = (value: Numeric): number => Math.round(Number(value ?? 0) * 100);

export const totalBalanceOf = (balances: Balances | null): number =>
  balances ? ACCOUNTS.reduce((sum, a) => sum + balances[a.value], 0) : 0;

export interface Tf2Summary {
  allocated: number;
  purchased: number;
  /** Negativo quando a meta foi batida — a tela trata isso como destaque, não como "falta comprar -30". */
  remaining: number;
  percent: number;
}

/**
 * Meta de TF2 do mês e quanto dela já foi comprado.
 *
 * A alocação grava duas pernas com a mesma quantidade (débito no Principal,
 * crédito no TF2); somar só a perna que credita o TF2 evita contar em dobro —
 * mesmo cuidado do prefill (ver FinancialMonthService::tf2AllocationPrefill).
 */
export const tf2SummaryOf = (movements: Movement[]): Tf2Summary => {
  const allocated = movements
    .filter((m) => m.category === 'tf2_allocation' && m.account_type === 'tf2')
    .reduce((sum, m) => sum + Number(m.quantity ?? 0), 0);

  // O que já saiu da verba, confirmado na Gamivo ou não — a categoria já
  // garante que é TF2 pago.
  const purchased = movements
    .filter((m) => m.category === 'tf2_purchase')
    .reduce((sum, m) => sum + Number(m.quantity ?? 0), 0);

  return {
    allocated,
    purchased,
    remaining: allocated - purchased,
    percent: allocated <= 0 ? 0 : Math.min(100, Math.round((purchased / allocated) * 100)),
  };
};
