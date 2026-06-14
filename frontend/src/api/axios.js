import axios from 'axios';
import { toast } from 'sonner';

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const data = error.response?.data;
    const status = error.response?.status;

    if (status === 403 && data?.code === 'ERR_AUTH_USER_SUSPENDED') {
      localStorage.removeItem('auth_token');
      localStorage.removeItem('temp_token');
      toast.error('Cuenta suspendida', {
        description: data.message,
      });
      window.location.href = '/login';
      return Promise.reject(error);
    }

    if (status === 401 && data?.code !== 'ERR_AUTH_INVALID_CREDENTIALS' && data?.code !== 'ERR_AUTH_INVALID_2FA_CODE') {
      localStorage.removeItem('auth_token');
      window.location.href = '/login';
    }

    return Promise.reject(error);
  }
);

export default api;
