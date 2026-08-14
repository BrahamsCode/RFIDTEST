/**
 * Mide el retardo real de `InventoryCycleProgressed` sobre Reverb.
 * Tareas 3.4 y 4.2. Criterio: menos de 3 s.
 *
 * Se conecta al WebSocket como lo haría el navegador, se suscribe al canal
 * del ciclo y cronometra desde que el servidor emite hasta que el mensaje
 * llega. No se mide la autorización del canal: eso ya tiene sus pruebas en
 * `BroadcastingTest`; aquí lo que interesa es el camino de datos.
 */
import { createHmac } from 'node:crypto';
import WebSocket from 'ws';

const CLAVE = process.env.REVERB_APP_KEY ?? 'clave-de-pruebas';
const SECRETO = process.env.REVERB_APP_SECRET ?? 'secreto-de-pruebas';
const HOST = process.env.REVERB_HOST ?? '127.0.0.1';
const PUERTO = process.env.REVERB_PORT ?? '8085';
const CANAL = process.argv[2] ?? 'private-inventory-cycle.1';
const ESPERADOS = Number(process.argv[3] ?? 5);

const ws = new WebSocket(`ws://${HOST}:${PUERTO}/app/${CLAVE}?protocol=7&client=js&version=8.4.0`);
const llegadas = [];

ws.on('open', () => process.stderr.write('  websocket conectado\n'));

ws.on('message', (raw) => {
  const msg = JSON.parse(raw.toString());

  if (msg.event === 'pusher:connection_established') {
    /*
     * Un canal privado exige firma HMAC del `socket_id`. En la aplicación la
     * calcula `/broadcasting/auth` contra la sesión; aquí se firma con el
     * secreto directamente, que es lo mismo que hace el servidor.
     *
     * Importa suscribirse al canal **privado** y no al público del mismo
     * nombre: son canales distintos, y suscribirse al público hace que no
     * llegue nada y parezca que la difusión no funciona.
     */
    const { socket_id } = JSON.parse(msg.data);
    const datos = { event: 'pusher:subscribe', data: { channel: CANAL } };

    if (CANAL.startsWith('private-') || CANAL.startsWith('presence-')) {
      const firma = createHmac('sha256', SECRETO)
        .update(`${socket_id}:${CANAL}`)
        .digest('hex');
      datos.data.auth = `${CLAVE}:${firma}`;
    }

    ws.send(JSON.stringify(datos));
    process.stderr.write(`  suscrito a ${CANAL}\n`);
    console.log('LISTO');
    return;
  }

  if (msg.event?.startsWith('pusher:') || msg.event?.startsWith('pusher_internal:')) return;

  const recibidoEn = Date.now();
  let emitidoEn = null;

  try {
    emitidoEn = JSON.parse(msg.data)?.emitido_en ?? null;
  } catch { /* el evento puede no llevar marca */ }

  llegadas.push({ evento: msg.event, en: recibidoEn, retardo: emitidoEn ? recibidoEn - emitidoEn : null });
  process.stderr.write(`  recibido ${msg.event} (${llegadas.length}/${ESPERADOS})\n`);

  if (llegadas.length >= ESPERADOS) {
    const r = llegadas.map((l) => l.retardo).filter((x) => x !== null).sort((a, b) => a - b);

    console.log(JSON.stringify({
      recibidos: llegadas.length,
      llegadas: llegadas.map((l) => l.en),
      p50: r.length ? r[Math.floor(0.5 * (r.length - 1))] : null,
      p95: r.length ? r[Math.floor(0.95 * (r.length - 1))] : null,
      max: r.length ? r[r.length - 1] : null,
    }));

    ws.close();
    process.exit(0);
  }
});

ws.on('error', (e) => { console.error('  error:', e.message); process.exit(1); });
setTimeout(() => { console.error('  tiempo agotado'); process.exit(1); }, 60_000);
