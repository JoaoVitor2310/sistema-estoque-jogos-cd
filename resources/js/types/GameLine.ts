export interface Fornecedor {
    id: number;
    perfilOrigem: string;
    quantidade_reclamacoes: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface TipoReclamacao {
    id: number;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

export interface TipoFormato {
    id: number;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

export interface Leilao {
    id: number;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

export interface Plataforma {
    id: number;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

export interface GameLine {
    id: number;
    color: string;
    steamId: string;
    chaveRecebida: string;
    repetido: boolean;
    plataformaIdentificada: string;
    nomeJogo: string;
    precoJogo: number | null;
    notaMetacritic: number | null;
    isSteam: boolean;
    randomClassificationG2A: string;
    randomClassificationKinguin: string;
    observacao: string;
    precoCliente: number | null;
    precoVenda: number | null;
    incomeReal: number | null;
    incomeSimulado: number | null;
    chaveEntregue: string;
    valorPagoTotal: string;
    valorPagoIndividual: number | null;
    vendido: boolean;
    leiloes: number | null;
    quantidade: number | null;
    devolucoes: boolean;
    lucroRS: number | null;
    lucroPercentual: number | null;
    dataAdquirida: string;
    dataVenda: string;
    dataVendida: string;
    perfilOrigem: string;
    email: string;
    
    fornecedor: Fornecedor;
    tipo_reclamacao: TipoReclamacao;
    tipo_formato: TipoFormato;
    leilao_g2a: Leilao;
    leilao_gamivo: Leilao;
    leilao_kinguin: Leilao;
    plataforma: Plataforma;
}