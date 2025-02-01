export type Fornecedor = {
    id: number;
    perfilOrigem: string;
    quantidade_reclamacoes: number;
    created_at: string | null;
    updated_at: string | null;
}

export type TipoReclamacao = {
    id: number;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

export type TipoFormato = {
    id: number;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

export type Leilao = {
    id: number;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

export type Plataforma = {
    id: number;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

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
    notaMetacritic: number | null;
    isSteam: boolean;
    randomClassificationG2A: string;
    randomClassificationKinguin: string;
    observacao: string;
    precoCliente: number | null;
    minimoParaVenda: number | null;
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
    tipo_reclamacao: TipoReclamacao;
    tipo_formato: TipoFormato;
    leilao_g2a: Leilao;
    leilao_gamivo: Leilao;
    leilao_kinguin: Leilao;
    plataforma: Plataforma;
}