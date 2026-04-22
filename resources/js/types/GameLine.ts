export type Fornecedor = {
    id: number;
    perfilOrigem: string;
    created_at: string | null;
    updated_at: string | null;
}

export type KeyFormat = 'RK' | 'DP' | 'GF' | 'SG' | 'FR' | 'CD' | 'T';
export type ClaimType = 'Nenhuma' | 'Dup' | 'Rev' | 'Reg';
export type SellPlatform = 'Nenhuma' | 'G2A' | 'Gamivo' | 'Kinguin';

export type GameLine = {
    id: number;
    color: string;
    steamId: string;
    idGamivo: string;
    chaveRecebida: string;
    repetido: boolean;
    plataformaIdentificada: string;
    nomeJogo: string;
    precoJogo: number | null;
    observacao: string;
    key_format: KeyFormat | null;
    claim_type: ClaimType | null;
    sell_platform: SellPlatform | null;
    precoCliente: number | null;
    minimoParaVenda: number | null;
    incomeSimulado: number | null;
    valorPagoTotal: string;
    valorPagoIndividual: number | null;
    lucroRS: number | null;
    lucroPercentual: number | null;
    valorVendido: number | null;
    lucroVendaRS: number | null;
    lucroVendaPercentual: number | null;
    dataAdquirida: string;
    dataVenda: string;
    dataVendida: string;
    perfilOrigem: string;
    email: string;
    minApiGamivo: number;
    maxApiGamivo: number;

    // Relacionamentos
    fornecedor: Fornecedor;
}
