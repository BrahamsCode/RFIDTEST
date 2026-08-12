import type { Stage } from '../Pipeline.js';
import type { PipelineContext, RawTagRead } from '../../types/TagRead.js';

/**
 * Una sola lectura de un tag es débil como evidencia: puede ser un rebote.
 * Se exige que el lector lo haya visto `minReadCount` veces.
 */
export class MinCountStage implements Stage {
  readonly name = 'min_count';

  process(read: RawTagRead, ctx: PipelineContext): RawTagRead | null {
    return read.readCount >= ctx.profile.minReadCount ? read : null;
  }
}
