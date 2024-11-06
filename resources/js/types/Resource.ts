export interface Resource {
    id: number;
    name: string;
    preco_euro: number;
    preco_dolar: number;
    preco_real: number;
    created_at?: string | null;
    updated_at?: string | null;
}