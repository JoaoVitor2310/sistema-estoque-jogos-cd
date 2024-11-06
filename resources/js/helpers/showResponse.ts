import { AxiosResponse } from 'axios';


export const showResponse = (res: AxiosResponse, toastAdd: Function) => {
    if (res.status === 200 || res.status === 201) {
        toastAdd({ severity: 'success', summary: 'Sucesso', detail: res.data.message, life: 3000 });
    } else {
        let errorMessages;
        if (typeof res.data.errors === 'object' && !Array.isArray(res.data.errors) && Object.keys(res.data.errors).length > 0) {
            errorMessages = Object.values(res.data.errors)
            .flat() // Se for um array de mensagens, o flat junta todas as mensagens
            .join(', '); // Concatena as mensagens com uma vírgula ou outro separador
            
        } else {
            errorMessages = res.data.message;
        }
        
        toastAdd({
            severity: 'error',
            summary: 'Erro',
            detail: errorMessages,
            life: 7000
        });
    }
}