export interface ApiErrorResponse {
    statusCode: number | string;  // Pode ser int ou string no PHP
    message: string;              // A mensagem de erro
    errors: string[]; // Pode ser uma MessageBag (definida separadamente) ou um array de strings
    data: any;
}