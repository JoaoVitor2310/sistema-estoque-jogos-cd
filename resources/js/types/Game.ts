export type Game = {
    id: number;
    name: string;
    region: string;
    gamivo_id: string;
    id_steamcharts: string;
    release_date: string;
    price_tf2: number;
    price_euro: number;
    popularity: number;
    created_at?: string | null;
    updated_at?: string | null;
}
