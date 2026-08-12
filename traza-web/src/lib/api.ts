import axios, { AxiosError } from 'axios';

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000',
  withCredentials: true,
  headers: { Accept: 'application/json' },
});

/** Sanctum exige el CSRF cookie antes de cualquier petición con sesión. */
export async function ensureCsrfCookie(): Promise<void> {
  await api.get('/sanctum/csrf-cookie');
}

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    // 401 significa sesión caducada: la aplicación vuelve al acceso en vez
    // de dejar pantallas a medio cargar.
    if (error.response?.status === 401 && !location.pathname.startsWith('/acceso')) {
      location.assign('/acceso');
    }
    return Promise.reject(error);
  },
);

export interface HealthResponse {
  status: 'ok' | 'degraded';
  version: string;
  epc_scheme: string;
  checks: Record<string, boolean>;
}

export async function fetchHealth(): Promise<HealthResponse> {
  const { data } = await api.get<HealthResponse>('/api/v1/health');
  return data;
}
