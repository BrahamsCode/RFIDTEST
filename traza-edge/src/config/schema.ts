import { z } from 'zod';

const boolish = z
  .enum(['true', 'false', '1', '0'])
  .transform((v) => v === 'true' || v === '1');

export const envSchema = z.object({
  NODE_ENV: z.enum(['development', 'production', 'test']).default('development'),

  TRAZA_API_URL: z.string().url(),
  TRAZA_DEVICE_CODE: z.string().min(1),
  TRAZA_DEVICE_TOKEN: z.string().min(1),
  TRAZA_LOCATION_CODE: z.string().min(1).default('LIM-01'),

  MQTT_URL: z.string().default('mqtt://mosquitto:1883'),
  MQTT_USERNAME: z.string().optional(),
  MQTT_PASSWORD: z.string().optional(),

  READER_MODE: z.enum(['llrp', 'webhook', 'simulator']).default('simulator'),
  READER_HOST: z.string().optional(),
  READER_PORT: z.coerce.number().int().positive().default(5084),
  READER_REGION: z.string().default('FCC-PE'),

  // El middleware descarta todo EPC que no empiece por esta máscara.
  EPC_MASK: z.string().min(2).default('3035D9'),
  EPC_TEST_PREFIX: z.string().min(2).default('FFFF'),

  SIMULATOR_SCENARIO: z.string().default('inventario_limpio'),
  SIMULATOR_POPULATION: z.coerce.number().int().positive().default(5000),
  SIMULATOR_MISS_RATE: z.coerce.number().min(0).max(1).default(0.03),
  SIMULATOR_STRAY_RATE: z.coerce.number().min(0).max(1).default(0.02),
  SIMULATOR_SEED: z.coerce.number().int().default(1),

  EDGE_BUFFER_PATH: z.string().default('./data/buffer.sqlite'),
  EDGE_BUFFER_MAX_ROWS: z.coerce.number().int().positive().default(2_000_000),
  EDGE_FLUSH_BATCH: z.coerce.number().int().positive().default(500),

  METRICS_PORT: z.coerce.number().int().positive().default(9100),

  PORTAL_MIN_CONFIDENCE: z.coerce.number().min(0).max(1).default(0.7),
  PORTAL_ENABLED: boolish.default('false'),
});

export type Env = z.infer<typeof envSchema>;
