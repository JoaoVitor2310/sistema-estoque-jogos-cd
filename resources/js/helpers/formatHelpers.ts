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

    console.error("Formato de data inválido:", dateString);
    return '';
};

export const formatDateToDB = (date: string): string => {
    return dayjs(date).isValid() ? dayjs(date).format('YYYY-MM-DD') : '';
};