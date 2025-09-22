import { Game } from "./Game";

export type Bundle = {
    id: number;
    name: string;
    type: string;
    description: string;
    minimum_price_tf2: number;
    price_dolar: number;
    release_date: string;
    created_at?: string | null;
    updated_at?: string | null;
    games: Game[];
}