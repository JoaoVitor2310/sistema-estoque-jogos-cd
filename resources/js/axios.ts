import axios, { AxiosInstance } from 'axios';

let instance: AxiosInstance | null = null;

class AxiosSingleton {
  constructor() {
    if (!instance) {
      instance = axios.create({
        validateStatus: function (status: number): boolean {
          return status >= 200 && status < 500;
        },
        // baseURL: '/api',
        // timeout: 5000,
      });
    }

    return instance;
  }
}

const axiosInstance: AxiosInstance = new AxiosSingleton() as AxiosInstance;

export default axiosInstance;