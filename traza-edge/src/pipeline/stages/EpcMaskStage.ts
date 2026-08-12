import type { Stage } from '../Pipeline.js';
import type { RawTagRead } from '../../types/TagRead.js';

export class EpcMaskStage implements Stage {
  readonly name = 'epc_mask';

  private readonly prefixes: string[];
  private readonly testPrefix: string;

  constructor(
    allowedPrefixes: string[],
    testPrefix: string,
    private readonly isProduction: boolean,
  ) {
    this.prefixes = allowedPrefixes.map((p) => p.toUpperCase());
    this.testPrefix = testPrefix.toUpperCase();
  }

  process(read: RawTagRead): RawTagRead | null {
    const epc = read.epc.toUpperCase();

    // En producción un EPC de laboratorio es un error grave: alguien dejó
    // un tag de pruebas en la tienda.
    if (this.isProduction && epc.startsWith(this.testPrefix)) return null;

    return this.prefixes.some((p) => epc.startsWith(p)) ? read : null;
  }
}
