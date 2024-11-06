export interface ApiOkResponse<T = any> {
    code: string | number,
    message: string,
    data: T | T[],
}