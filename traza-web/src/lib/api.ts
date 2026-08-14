import axios, { AxiosError } from 'axios';
import type { Tag, TagState } from './domain';

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

/** Errores RFC 7807 del backend. */
export interface Problem {
  type: string;
  title: string;
  status: number;
  detail?: string;
  errors?: Record<string, string[]>;
}

export function problemFrom(error: unknown): Problem | null {
  const data = (error as AxiosError)?.response?.data;
  return data && typeof data === 'object' && 'title' in data ? (data as Problem) : null;
}

/** Mensaje legible de cualquier error de la API. */
export function messageFrom(error: unknown, fallback = 'Algo ha ido mal.'): string {
  const problem = problemFrom(error);
  return problem?.detail ?? problem?.title ?? fallback;
}

export interface Paginated<T> {
  data: T[];
  meta: { total: number; per_page: number; current_page: number; last_page: number };
}

// ---------------------------------------------------------------- salud

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

// -------------------------------------------------------------- usuario

export interface CurrentUser {
  id: number;
  name: string;
  email: string;
  organization_id: number;
  default_location_id: number | null;
}

export async function fetchUser(): Promise<CurrentUser> {
  const { data } = await api.get<CurrentUser>('/api/v1/user');
  return data;
}

export async function login(email: string, password: string): Promise<void> {
  await ensureCsrfCookie();
  await api.post('/login', { email, password });
}

export async function logout(): Promise<void> {
  await api.post('/logout');
}

// --------------------------------------------------------------- ciclos

export type CycleStatus =
  | 'borrador' | 'en_curso' | 'pausado' | 'conciliando' | 'cerrado' | 'cancelado';

export interface Cycle {
  id: number;
  code: string;
  status: CycleStatus;
  scope: string;
  location_id: number;
  expected_count: number | null;
  scanned_count: number;
  found_count: number | null;
  missing_count: number | null;
  unexpected_count: number | null;
  accuracy_pct: number | null;
  started_at: string | null;
  closed_at: string | null;
}

export interface ZonePerformance {
  zone_id: number | null;
  zone_name: string | null;
  expected: number;
  found: number;
  missing: number;
  accuracy_pct: string | null;
}

export interface CycleReport {
  cycle: Cycle;
  lines: Array<{
    sku: string;
    expected_qty: number;
    counted_qty: number;
    difference_qty: number;
    value_difference: string | null;
  }>;
}

export const cycles = {
  async list(params: Record<string, unknown> = {}): Promise<Paginated<Cycle>> {
    const { data } = await api.get('/api/v1/inventory-cycles', { params });
    return data;
  },
  async get(id: number): Promise<Cycle> {
    const { data } = await api.get(`/api/v1/inventory-cycles/${id}`);
    return data;
  },
  async zonePerformance(id: number): Promise<ZonePerformance[]> {
    const { data } = await api.get(`/api/v1/inventory-cycles/${id}/zone-performance`);
    return data.zones;
  },
  async report(id: number): Promise<CycleReport> {
    const { data } = await api.get(`/api/v1/inventory-cycles/${id}/report`);
    return data;
  },
  async pause(id: number): Promise<Cycle> {
    const { data } = await api.post(`/api/v1/inventory-cycles/${id}/pause`);
    return data;
  },
  async start(id: number): Promise<Cycle> {
    const { data } = await api.post(`/api/v1/inventory-cycles/${id}/start`);
    return data;
  },
  async close(id: number): Promise<unknown> {
    const { data } = await api.post(`/api/v1/inventory-cycles/${id}/close`, {}, {
      headers: { 'Idempotency-Key': `close-cycle-${id}` },
    });
    return data;
  },
};

// ----------------------------------------------------------------- tags

export interface TagFilters {
  epc?: string;
  state?: TagState | '';
  location?: number;
  page?: number;
}

export interface TagDetail extends Tag {
  epc_scheme: string;
  commissioned_at: string | null;
  first_seen_at: string | null;
  sold_at: string | null;
  replaces_tag_id: number | null;
  sale: { sale_code: string; external_ref: string | null; sold_at: string; unit_price: string } | null;
}

export interface Movement {
  id: number;
  type: string;
  state_before: TagState | null;
  state_after: TagState | null;
  from_location_id: number | null;
  to_location_id: number | null;
  from_zone_id: number | null;
  to_zone_id: number | null;
  reason: string | null;
  reference_type: string | null;
  reference_id: number | null;
  occurred_at: string;
}

export interface Detection {
  hour: string;
  device_id: number | null;
  device_code: string | null;
  antenna: number | null;
  reads: number;
  rssi_avg: number;
  rssi_min: number;
  rssi_max: number;
}

export interface Detections {
  epc: string;
  hours: number;
  data: Detection[];
  summary: {
    total_reads: number;
    rssi_avg: number | null;
    rssi_min: number | null;
    /** RSSI medio por debajo de −70 dBm: se está leyendo de lejos. */
    weak_signal: boolean;
  };
}

export const tags = {
  async list(filters: TagFilters): Promise<Paginated<Tag>> {
    const { data } = await api.get('/api/v1/tags', { params: filters });
    return data;
  },
  async get(epc: string): Promise<TagDetail> {
    const { data } = await api.get(`/api/v1/tags/${epc}`);
    return data;
  },
  async history(epc: string, page = 1): Promise<Paginated<Movement>> {
    const { data } = await api.get(`/api/v1/tags/${epc}/history`, { params: { page } });
    return data;
  },
  async detections(epc: string, hours = 72): Promise<Detections> {
    const { data } = await api.get(`/api/v1/tags/${epc}/detections`, { params: { hours } });
    return data;
  },
};

// ---------------------------------------------------------------- stock

export interface StockRow {
  location_id: number;
  zone_id: number | null;
  product_variant_id: number;
  sku: string;
  size: string | null;
  color: string | null;
  product_name: string;
  zone_name: string | null;
  quantity: number;
  sellable_quantity: number;
  last_seen_at: string | null;
}

export interface StockSummary {
  units: number;
  sellable_units: number;
  last_accuracy_pct: number | null;
  last_cycle_closed_at: string | null;
  open_alerts: number;
  replenishment_needed: number;
}

export interface ReplenishmentRow {
  location_id: number;
  product_variant_id: number;
  sku: string;
  product_name: string;
  size: string | null;
  color: string | null;
  on_floor: number;
  in_back: number;
  min_stock: number;
}

export const stock = {
  async list(params: Record<string, unknown> = {}): Promise<Paginated<StockRow>> {
    const { data } = await api.get('/api/v1/stock', { params });
    return data;
  },
  async summary(locationId?: number): Promise<StockSummary> {
    const { data } = await api.get('/api/v1/stock/summary', { params: { location: locationId } });
    return data;
  },
  async aging(locationId?: number): Promise<Array<{ age_bucket: string; units: number }>> {
    const { data } = await api.get('/api/v1/stock/aging', { params: { location: locationId } });
    return data.data;
  },
  async valuation(locationId?: number) {
    const { data } = await api.get('/api/v1/stock/valuation', { params: { location: locationId } });
    return data as {
      data: Array<{ location_name: string; units: number; cost_value: string; retail_value: string }>;
      totals: { units: number; cost_value: number; retail_value: number };
    };
  },
  async replenishment(locationId?: number): Promise<Paginated<ReplenishmentRow>> {
    const { data } = await api.get('/api/v1/stock/replenishment', { params: { location: locationId } });
    return data;
  },
};

// -------------------------------------------------------------- alertas

export interface Alert {
  id: number;
  kind: string;
  severity: number;
  status: string;
  title: string;
  detail: Record<string, unknown>;
  tag_id: number | null;
  device_id: number | null;
  triggered_at: string;
}

export const alerts = {
  async list(params: Record<string, unknown> = {}): Promise<Paginated<Alert>> {
    const { data } = await api.get('/api/v1/alerts', { params });
    return data;
  },
  async acknowledge(id: number): Promise<void> {
    await api.post(`/api/v1/alerts/${id}/acknowledge`);
  },
  async resolve(id: number, falsePositive = false, note?: string): Promise<void> {
    await api.post(`/api/v1/alerts/${id}/resolve`, {
      false_positive: falsePositive,
      resolution_note: note,
    });
  },
};

// --------------------------------------------------------------- portal

export interface PortalEvent {
  id: number;
  location_id: number;
  device_id: number;
  tag_id: number | null;
  epc: string;
  direction: 'salida' | 'entrada' | 'indeterminado' | null;
  confidence: number | null;
  was_sold: boolean | null;
  alarm_raised: boolean;
  occurred_at: string;
}

export interface PortalStats {
  location_id: number;
  days: number;
  alarms: number;
  dismissed: number;
  rate: number;
  threshold: number;
  needs_recalibration: boolean;
}

export const portal = {
  async list(params: Record<string, unknown> = {}): Promise<Paginated<PortalEvent>> {
    const { data } = await api.get('/api/v1/portal-events', { params });
    return data;
  },
  async stats(locationId: number, days = 30): Promise<PortalStats> {
    const { data } = await api.get('/api/v1/portal-events/stats', {
      params: { location: locationId, days },
    });
    return data;
  },
  async markFalsePositive(id: number, note?: string): Promise<void> {
    await api.post(`/api/v1/portal-events/${id}/false-positive`, { note });
  },
};

// -------------------------------------------------------- dispositivos

export type DeviceKind = 'handheld' | 'lector_fijo' | 'impresora' | 'edge';

export interface Device {
  id: number;
  code: string;
  name: string;
  kind: DeviceKind;
  status: string;
  location_id: number | null;
  firmware: string | null;
  last_seen_at: string | null;
  is_online: boolean;
  has_pending_enrollment: boolean;
}

/** Lo que se codifica en el QR de alta. Ver `docs/09` §10. */
export interface EnrollmentPayload {
  v: number;
  url: string;
  device_code: string;
  enrollment_token: string;
  location_id: number | null;
}

export interface Enrollment {
  device_code: string;
  expires_at: string;
  expires_in_minutes: number;
  qr_payload: EnrollmentPayload;
}

export const devices = {
  async list(params: Record<string, unknown> = {}): Promise<Paginated<Device>> {
    const { data } = await api.get('/api/v1/devices', { params });
    return data;
  },
  async enroll(id: number, baseUrl?: string): Promise<Enrollment> {
    const { data } = await api.post(`/api/v1/devices/${id}/enrollment`, {
      base_url: baseUrl,
    });
    return data;
  },
  async revokeEnrollment(id: number): Promise<void> {
    await api.delete(`/api/v1/devices/${id}/enrollment`);
  },
};

// ----------------------------------------------- catálogo y etiquetas

export interface Variant {
  id: number;
  sku: string;
  size: string | null;
  color: string | null;
  color_hex: string | null;
  barcode: string | null;
  item_reference: string | null;
  cost_price: string | null;
  sale_price: string | null;
  min_stock: number;
  is_active: boolean;
  serials_remaining: number;
}

export interface CatalogProduct {
  id: number;
  code: string;
  name: string;
  brand: string | null;
  composition: string | null;
  /** 1 fácil (algodón), 5 difícil (metálico). Explica por qué algo no se lee. */
  rfid_difficulty: number | null;
  is_active: boolean;
  variants: Variant[];
}

export interface LabelBatch {
  id: number;
  code: string;
  sku: string | null;
  quantity: number;
  serial_from: number;
  serial_to: number;
  printed_ok: number;
  printed_void: number;
  void_rate: number;
  void_rate_exceeded: boolean;
  created_at: string;
  completed_at: string | null;
}

export interface CreatedBatch {
  id: number;
  code: string;
  sku: string;
  quantity: number;
  serial_from: number;
  serial_to: number;
  zpl_url: string;
}

export const catalog = {
  async products(params: Record<string, unknown> = {}): Promise<Paginated<CatalogProduct>> {
    const { data } = await api.get('/api/v1/products', { params });
    return data;
  },
};

export const labels = {
  async batches(params: Record<string, unknown> = {}): Promise<Paginated<LabelBatch>> {
    const { data } = await api.get('/api/v1/label-batches', { params });
    return data;
  },
  async create(variantId: number, quantity: number): Promise<CreatedBatch> {
    const { data } = await api.post(
      '/api/v1/label-batches',
      { product_variant_id: variantId, quantity },
      // Emitir un lote consume seriales de forma irreversible: un reintento
      // por timeout no puede quemar cien EPC más.
      { headers: { 'Idempotency-Key': `lote-${variantId}-${quantity}-${Date.now()}` } },
    );
    return data;
  },
  async complete(id: number, printedOk: number, printedVoid: number): Promise<LabelBatch> {
    const { data } = await api.post(`/api/v1/label-batches/${id}/complete`, {
      printed_ok: printedOk,
      printed_void: printedVoid,
    });
    return data;
  },
  /** Descarga del ZPL. Va como fichero: se manda al puerto 9100 de la Zebra. */
  async downloadZpl(batchId: number, code: string): Promise<void> {
    const { data } = await api.get(`/api/v1/label-batches/${batchId}/zpl`, {
      responseType: 'blob',
    });

    const url = URL.createObjectURL(data as Blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${code}.zpl`;
    link.click();
    URL.revokeObjectURL(url);
  },
};
