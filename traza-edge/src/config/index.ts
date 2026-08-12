import { envSchema, type Env } from './schema.js';

let cached: Env | undefined;

export function loadConfig(source: NodeJS.ProcessEnv = process.env): Env {
  const parsed = envSchema.safeParse(source);

  if (!parsed.success) {
    const detail = parsed.error.issues
      .map((i) => `  - ${i.path.join('.') || '(raíz)'}: ${i.message}`)
      .join('\n');
    throw new Error(`Configuración de entorno inválida:\n${detail}`);
  }

  return parsed.data;
}

export function config(): Env {
  cached ??= loadConfig();
  return cached;
}

export type { Env };
