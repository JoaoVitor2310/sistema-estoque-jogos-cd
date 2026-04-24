export type Fornecedor = {
    id: number;
    supplier_url: string;
    created_at: string | null;
    updated_at: string | null;
}

export type KeyFormat = 'RK' | 'DP' | 'GF' | 'SG' | 'FR' | 'CD' | 'T';
export type ClaimType = 'Nenhuma' | 'Dup' | 'Rev' | 'Reg';
export type SellPlatform = 'Nenhuma' | 'G2A' | 'Gamivo' | 'Kinguin';

export type GameLine = {
    id: number;
    color: string;
    steam_id: string;
    gamivo_id: string;
    key_code: string;
    is_duplicate: boolean;
    identified_platform: string;
    game_name: string;
    notes: string;
    key_format: KeyFormat | null;
    claim_type: ClaimType | null;
    sell_platform: SellPlatform | null;
    market_price: number | null;
    minimum_sale_price: number | null;
    simulated_income: number | null;
    total_paid: string;
    individual_cost: number | null;
    purchase_profit: number | null;
    purchase_profit_percent: number | null;
    sold_price: number | null;
    sale_profit: number | null;
    sale_profit_percent: number | null;
    acquired_at: string;
    listed_at: string;
    sold_at: string;
    supplier_url: string;
    email: string;
    min_api: number;
    max_api: number;

    // Relacionamentos
    fornecedor: Fornecedor;
}
