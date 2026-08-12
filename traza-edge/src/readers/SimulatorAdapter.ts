import { EventEmitter } from 'node:events';
import type { RawTagRead, ReadProfile } from '../types/TagRead.js';
import type { ReaderAdapter } from './ReaderAdapter.js';
import { createRng } from './rng.js';
import { resolveScenario, type ScenarioSpec } from './scenarios.js';

export interface SimulatorOptions {
  epcPrefix: string;
  scenario: string;
  seed: number;
  /** Sobrescribe los valores del escenario; lo usan las pruebas. */
  overrides?: Partial<ScenarioSpec>;
}

/**
 * Lector sintético. Es un entregable de primera clase: sin él nadie puede
 * desarrollar ni probar el borde sin hardware.
 */
export class SimulatorAdapter extends EventEmitter implements ReaderAdapter {
  private timer?: NodeJS.Timeout;
  private readonly spec: ScenarioSpec;
  private readonly rng: () => number;
  private readonly population: string[];
  private profile?: ReadProfile;
  private tick = 0;

  constructor(
    public readonly id: string,
    private readonly options: SimulatorOptions,
  ) {
    super();
    this.spec = { ...resolveScenario(options.scenario), ...options.overrides };
    this.rng = createRng(options.seed);
    this.population = this.generatePopulation();
  }

  get scenario(): ScenarioSpec {
    return this.spec;
  }

  async connect(): Promise<void> {}

  async disconnect(): Promise<void> {
    await this.stopInventory();
  }

  async applyProfile(profile: ReadProfile): Promise<void> {
    this.profile = profile;
  }

  async startInventory(): Promise<void> {
    if (this.timer) return;
    const intervalMs = Math.max(1, Math.round(1000 / this.spec.readsPerSecond));
    this.timer = setInterval(() => this.emitOnce(), intervalMs);
  }

  async stopInventory(): Promise<void> {
    if (this.timer) clearInterval(this.timer);
    this.timer = undefined;
  }

  /** Genera `count` lecturas sin temporizador. Es la vía que usan las pruebas. */
  generate(count: number, startAt = Date.now()): RawTagRead[] {
    const out: RawTagRead[] = [];
    for (let i = 0; i < count; i++) {
      const read = this.nextRead(startAt + i * 5);
      if (read) out.push(read);
    }
    return out;
  }

  private emitOnce(): void {
    const read = this.nextRead(Date.now());
    if (read) this.emit('read', read);
  }

  private nextRead(now: number): RawTagRead | null {
    this.tick++;

    if (this.rng() < this.spec.strayRate) {
      return this.makeRead(this.randomForeignEpc(), true, now);
    }

    // Fallo de lectura: prueba que el sistema tolera huecos.
    if (this.rng() < this.spec.missRate) return null;

    const index = Math.floor(this.rng() * this.population.length);
    const epc = this.population[index] ?? this.population[0]!;
    return this.makeRead(epc, false, now);
  }

  private makeRead(epc: string, stray: boolean, now: number): RawTagRead {
    const read: RawTagRead = {
      epc,
      antennaPort: this.antennaFor(),
      // Los tags ajenos llegan con RSSI baja: es lo que los delata.
      rssi: stray ? -75 + this.rng() * 8 : -58 + this.rng() * 20,
      firstSeen: now,
      lastSeen: now,
      readCount: 1 + Math.floor(this.rng() * 5),
      readerId: this.id,
    };

    if (this.profile?.readTid) read.tid = this.tidFor(epc);
    return read;
  }

  /**
   * En los escenarios de portal las antenas impares son interiores y las
   * pares exteriores. Un cruce normal recorre interior→exterior; el
   * escenario dudoso vuelve al interior sin completar el cruce.
   */
  private antennaFor(): number {
    if (this.spec.kind !== 'portal') {
      return 1 + Math.floor(this.rng() * this.spec.antennas);
    }

    const phase = this.tick % 6;
    if (this.spec.portalReverses) {
      return phase < 2 ? 1 : phase < 4 ? 2 : 1;
    }
    return phase < 3 ? 1 : 2;
  }

  private generatePopulation(): string[] {
    const set = new Set<string>();
    while (set.size < this.spec.populationSize) {
      set.add(this.makeEpc(this.options.epcPrefix));
    }
    return [...set];
  }

  private randomForeignEpc(): string {
    return this.makeEpc('E28011');
  }

  private makeEpc(prefix: string): string {
    const width = Math.max(0, 24 - prefix.length);
    let body = '';
    for (let i = 0; i < width; i++) {
      body += Math.floor(this.rng() * 16)
        .toString(16)
        .toUpperCase();
    }
    return (prefix + body).toUpperCase();
  }

  private tidFor(epc: string): string {
    let h = 0;
    for (const ch of epc) h = (Math.imul(h, 31) + ch.charCodeAt(0)) >>> 0;
    return `E280${h.toString(16).toUpperCase().padStart(20, '0')}`.slice(0, 24);
  }
}
