export interface Resource {
    id: number;
    name: string;
    price_euro: number;
    price_dollar: number;
    price_brl: number;
    created_at?: string | null;
    updated_at?: string | null;
}