export interface ScenarioSpec {
  readonly description: string;
  readonly populationSize: number;
  readonly readsPerSecond: number;
  readonly missRate: number;
  readonly strayRate: number;
  readonly antennas: number;
  readonly kind: 'inventario' | 'portal' | 'recepcion';
  /** El portal dudoso hace que el tag entre y retroceda sin llegar a cruzar. */
  readonly portalReverses?: boolean;
  /** La API responde 503 durante este tiempo; lo consume el escenario de red. */
  readonly apiOutageMs?: number;
}

/** Los 7 escenarios de `docs/07-middleware-rfid.md` §8. */
export const SCENARIOS: Record<string, ScenarioSpec> = {
  inventario_limpio: {
    description: 'Camino feliz, rendimiento de ingesta',
    populationSize: 5000,
    readsPerSecond: 400,
    missRate: 0.02,
    strayRate: 0,
    antennas: 1,
    kind: 'inventario',
  },
  inventario_dificil: {
    description: 'Umbral de missed_cycles y filtro de máscara',
    populationSize: 5000,
    readsPerSecond: 400,
    missRate: 0.15,
    strayRate: 0.05,
    antennas: 1,
    kind: 'inventario',
  },
  portal_salida: {
    description: '3 tags cruzando, secuencia interior→exterior',
    populationSize: 3,
    readsPerSecond: 20,
    missRate: 0,
    strayRate: 0,
    antennas: 4,
    kind: 'portal',
  },
  portal_dudoso: {
    description: 'Tag que se acerca y retrocede; no debe disparar alarma',
    populationSize: 1,
    readsPerSecond: 20,
    missRate: 0,
    strayRate: 0,
    antennas: 4,
    kind: 'portal',
    portalReverses: true,
  },
  vecino_ruidoso: {
    description: '90 % de lecturas con prefijo ajeno',
    populationSize: 500,
    readsPerSecond: 200,
    missRate: 0,
    strayRate: 0.9,
    antennas: 1,
    kind: 'inventario',
  },
  red_caida: {
    description: 'La API devuelve 503 durante 10 min',
    populationSize: 2000,
    readsPerSecond: 100,
    missRate: 0.02,
    strayRate: 0,
    antennas: 1,
    kind: 'inventario',
    apiOutageMs: 600_000,
  },
  avalancha: {
    description: '8 000 lecturas/s durante 60 s',
    populationSize: 20000,
    readsPerSecond: 8000,
    missRate: 0,
    strayRate: 0,
    antennas: 4,
    kind: 'inventario',
  },
};

export function resolveScenario(name: string): ScenarioSpec {
  const spec = SCENARIOS[name];
  if (!spec) {
    throw new Error(
      `Escenario desconocido "${name}". Disponibles: ${Object.keys(SCENARIOS).join(', ')}`,
    );
  }
  return spec;
}
