import type { ProcessedTagRead } from '../types/TagRead.js';

/** Mensaje que viaja por `traza/{tienda}/portal`. Lo consume `traza:listen-portal`. */
export interface PortalMessage {
  deviceCode: string;
  epc: string;
  direction: string;
  confidence: number;
  occurredAt: string;
  evidence: unknown;
}

/** Lo mínimo de `mqtt.MqttClient` que hace falta; así se puede sustituir en pruebas. */
export interface MqttLike {
  publishAsync(
    topic: string,
    message: string,
    opts: { qos: 0 | 1 | 2 },
  ): Promise<unknown>;
  readonly connected: boolean;
}

/** Respaldo HTTP para cuando no hay broker o está caído. */
export interface PortalFallback {
  postPortalEvent(message: PortalMessage): Promise<void>;
}

export interface PortalPublisherOptions {
  locationCode: string;
  deviceCode: string;
  minConfidence: number;
  mqtt?: MqttLike;
  fallback?: PortalFallback;
  onError?: (err: Error) => void;
}

/**
 * Camino rápido del portal (`docs/07` §6).
 *
 * No pasa por el buffer ni por la cola de ingesta: el presupuesto es de
 * 800 ms extremo a extremo y una alarma que suena cuando la persona ya salió
 * de la tienda no sirve de nada. La lectura sigue yendo al buffer aparte,
 * para el registro histórico.
 */
export class PortalPublisher {
  private published = 0;
  private suppressed = 0;
  private failed = 0;

  constructor(private readonly options: PortalPublisherOptions) {}

  get topic(): string {
    return `traza/${this.options.locationCode}/portal`;
  }

  /** ¿Este tránsito merece salir por el camino rápido? */
  shouldPublish(read: ProcessedTagRead): boolean {
    return (
      read.direction === 'salida' &&
      (read.confidence ?? 0) >= this.options.minConfidence
    );
  }

  /**
   * Nunca lanza. Un fallo del broker no puede tumbar el bucle de lectura:
   * el tránsito ya está en el buffer y llegará por la vía normal, tarde pero
   * completo.
   */
  async publish(read: ProcessedTagRead): Promise<boolean> {
    if (!this.shouldPublish(read)) {
      this.suppressed++;
      return false;
    }

    const message: PortalMessage = {
      deviceCode: this.options.deviceCode,
      epc: read.epc,
      direction: read.direction ?? 'indeterminado',
      confidence: read.confidence ?? 0,
      occurredAt: new Date(read.lastSeen).toISOString(),
      evidence: read.evidence ?? {},
    };

    try {
      if (this.options.mqtt?.connected) {
        // QoS 1: perder una alarma es peor que repetirla, y el backend
        // tolera el duplicado porque cada tránsito es una fila más.
        await this.options.mqtt.publishAsync(this.topic, JSON.stringify(message), { qos: 1 });
      } else if (this.options.fallback) {
        await this.options.fallback.postPortalEvent(message);
      } else {
        this.failed++;
        return false;
      }

      this.published++;
      return true;
    } catch (err) {
      this.failed++;
      this.options.onError?.(err instanceof Error ? err : new Error(String(err)));
      return false;
    }
  }

  /** Contadores para `/metrics`. */
  snapshot(): Record<string, number> {
    return {
      'portal.published': this.published,
      'portal.suppressed': this.suppressed,
      'portal.failed': this.failed,
    };
  }
}
