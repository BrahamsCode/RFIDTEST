import type { Stage } from '../Pipeline.js';
import type { PipelineContext, RawTagRead } from '../../types/TagRead.js';

export class RssiThresholdStage implements Stage {
  readonly name = 'rssi_threshold';

  process(read: RawTagRead, ctx: PipelineContext): RawTagRead | null {
    // El umbral por antena gana sobre el del perfil: la antena de caja
    // necesita ser mucho más selectiva que la de un pasillo.
    const antenna = ctx.antennaConfig.get(read.antennaPort);
    const threshold = antenna?.rssiThreshold ?? ctx.profile.rssiThreshold;

    if (threshold === undefined) return read;
    return read.rssi >= threshold ? read : null;
  }
}
