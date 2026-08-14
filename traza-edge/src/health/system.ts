import { cpus } from 'node:os';
import { readFile } from 'node:fs/promises';

/**
 * Uso de CPU del equipo, en porcentaje.
 *
 * Se calcula por diferencia entre dos muestras de `os.cpus()`: el valor
 * absoluto de `times` es acumulado desde el arranque, así que una sola
 * lectura daría la media de toda la vida del proceso y nunca variaría.
 */
export class CpuSampler {
  private previous = snapshotCpu();

  /** @returns porcentaje 0..100 desde la última llamada. */
  percent(): number {
    const current = snapshotCpu();
    const idle = current.idle - this.previous.idle;
    const total = current.total - this.previous.total;

    this.previous = current;

    if (total <= 0) return 0;

    return Math.round(((total - idle) / total) * 1000) / 10;
  }
}

function snapshotCpu(): { idle: number; total: number } {
  let idle = 0;
  let total = 0;

  for (const cpu of cpus()) {
    for (const [kind, value] of Object.entries(cpu.times)) {
      total += value;
      if (kind === 'idle') idle += value;
    }
  }

  return { idle, total };
}

/**
 * Temperatura del equipo en grados.
 *
 * Un mini-PC sin ventilación en la trastienda de una galería de Lima se
 * calienta, y un lector que se apaga por temperatura a las tres de la tarde
 * se diagnostica muy mal sin este dato. Devuelve null donde no haya sensor:
 * el latido tolera campos ausentes a propósito.
 */
export async function temperatureC(): Promise<number | null> {
  for (const path of [
    '/sys/class/thermal/thermal_zone0/temp',
    '/sys/devices/virtual/thermal/thermal_zone0/temp',
  ]) {
    try {
      const raw = (await readFile(path, 'utf8')).trim();
      const value = Number(raw);

      if (!Number.isFinite(value)) continue;

      // El kernel expone milésimas de grado; algunas placas dan grados.
      return Math.round((value > 1000 ? value / 1000 : value) * 10) / 10;
    } catch {
      // Sin sensor en esta ruta: se prueba la siguiente.
    }
  }

  return null;
}
