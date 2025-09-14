import { Game } from "./Game";

export type Bundle = {
    id: number;
    name: string;
    type: string;
    description: string;
    price_tf2: number;
    price_euro: number;
    release_date: string;
    created_at?: string | null;
    updated_at?: string | null;
    games: Game[];
}