import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '../lib/echo';

export interface PortalAlarm {
  epc: string;
  confidence: number;
  tag_id: number | null;
  product_name: string | null;
  occurred_at: string;
}

/**
 * Alarmas del portal en vivo. Ver `docs/06` §8.
 *
 * Sin Reverb configurado devuelve una lista vacía y la pantalla se apoya en
 * el sondeo del listado. Se pierde inmediatez, que en una alarma es casi
 * todo, pero la aplicación no se rompe.
 */
export function usePortalAlarms(locationId: number | null, keep = 10): PortalAlarm[] {
  const [alarms, setAlarms] = useState<PortalAlarm[]>([]);
  const queryClient = useQueryClient();

  useEffect(() => {
    if (echo === null || locationId === null) return;

    const name = `location.${locationId}.alerts`;

    echo.private(name).listen('.PortalAlarmRaised', (event: PortalAlarm) => {
      // Las más recientes arriba y sin cola infinita: quien mira la puerta
      // solo necesita las de este rato.
      setAlarms((previous) => [event, ...previous].slice(0, keep));

      // El tránsito ya está guardado; se recarga el listado para que el
      // botón de falso positivo tenga su identificador.
      void queryClient.invalidateQueries({ queryKey: ['portal-events'] });
    });

    return () => {
      echo.leave(name);
    };
  }, [locationId, keep, queryClient]);

  return alarms;
}
