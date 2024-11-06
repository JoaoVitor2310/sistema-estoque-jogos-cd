export interface AuthorizedUsers {
    id: number;
    name: string;
    email: string;
    status: boolean;
    created_at?: string | null;
    updated_at?: string | null;
}