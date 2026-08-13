import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '../lib/echo';

export interface CycleProgress {
  scanned: number;
  expected: number;
  progress: number;
  zone_id: number | null;
}

/**
 * Avance del ciclo por WebSocket. Ver `docs/08` §4.
 *
 * Devuelve null si Reverb no está configurado; en ese caso la pantalla se
 * apoya en el sondeo de respaldo. Así la aplicación funciona igual en un
 * entorno sin Reverb, solo con menos inmediatez.
 */
export function useCycleProgress(cycleId: number): CycleProgress | null {
  const [progress, setProgress] = useState<CycleProgress | null>(null);
  const queryClient = useQueryClient();

  useEffect(() => {
    if (echo === null) return;

    const channel = echo.private(`inventory-cycle.${cycleId}`);

    channel.listen('.InventoryCycleProgressed', (event: CycleProgress) => {
      setProgress(event);

      // Se invalida solo el desglose por zona, no el ciclo entero: recargar
      // todo en cada evento haría parpadear la pantalla.
      void queryClient.invalidateQueries({ queryKey: ['cycle', cycleId, 'zones'] });
    });

    return () => {
      echo.leave(`inventory-cycle.${cycleId}`);
    };
  }, [cycleId, queryClient]);

  return progress;
}
