import dayjs from "dayjs";

export const formatDateToBR = (dateString: string): string => {
    if (!dateString) return '';

    if (dateString.includes('-')) {
        const [year, month, day] = dateString.split('-');
        return `${day.padStart(2, '0')}/${month.padStart(2, '0')}/${year}`;
    }

    const date = new Date(dateString);

    if (!isNaN(date.getTime())) {
        const day = String(date.getUTCDate()).padStart(2, '0');
        const month = String(date.getUTCMonth() + 1).padStart(2, '0');
        const year = date.getUTCFullYear();

        return `${day}/${month}/${year}`;
    }

    // console.error("Formato de data inválido:", dateString);
    return '';
};

export const formatDateToDB = (date: string): string => {
    return dayjs(date).isValid() ? dayjs(date).format('YYYY-MM-DD') : '';
};

export const convertToDbDate = (brDate) => {
    if (!brDate || typeof brDate !== 'string') return null;

    const [day, month, year] = brDate.split('/').map(Number);

    // Verifica se os valores são válidos
    if (!day || !month || !year || day > 31 || month > 12) return null;

    // Retorna no formato yyyy/mm/dd
    return `${year.toString().padStart(4, '0')}/${month.toString().padStart(2, '0')}/${day.toString().padStart(2, '0')}`;
};

export const identifyAndFormatDate = (date: string): string => {
    const brazilianDatePattern = /^\d{2}[-/]\d{2}[-/]\d{4}$/; // DD/MM/YYYY ou DD-MM-YYYY
    const americanDatePattern = /^\d{4}[-/]\d{2}[-/]\d{2}$/; // YYYY/MM/DD ou YYYY-MM-DD

    if (brazilianDatePattern.test(date)) {
        // Converte a data brasileira para o formato americano (com traços) e depois formata para o banco
        const [day, month, year] = date.split(/[-/]/); // Divide por barra ou traço
        const formattedDate = `${year}-${month}-${day}`;
        return formatDateToDB(formattedDate);
    } else if (americanDatePattern.test(date)) {
        // Converte a data americana para o formato brasileiro (com traços) e depois formata para o banco
        const [year, month, day] = date.split(/[-/]/); // Divide por barra ou traço
        const formattedDate = `${year}/${month}/${day}`;
        return formatDateToDB(formattedDate);
    }

    // Retorna uma string vazia se o formato for inválido
    return '';
};