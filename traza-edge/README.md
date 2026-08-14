# traza-edge

Middleware RFID de borde. Se despliega en cada tienda. Ver `docs/07-middleware-rfid.md`.

Es un componente **desechable**: si se reinstala desde cero no se pierde ningún
dato de negocio, solo lo que hubiera en su buffer local.

## Puesta en marcha

```bash
npm install
cp ../infra/.env.example .env      # ajustar TRAZA_API_URL y TRAZA_DEVICE_TOKEN
npm run dev
```

Sin lector físico no hay que hacer nada especial: `READER_MODE=simulator` es el
valor por defecto.

## Comandos

| Comando | Qué hace |
|---|---|
| `npm run dev` | Arranca con recarga en caliente |
| `npm run build` | Compila a `dist/` |
| `npm start` | Ejecuta lo compilado |
| `npm run typecheck` | Comprobación de tipos sin emitir |
| `npm test` | Pruebas con Vitest |

## Escenarios del simulador

Se elige con `SIMULATOR_SCENARIO`. Todos son reproducibles fijando
`SIMULATOR_SEED`.

| Escenario | Qué valida |
|---|---|
| `inventario_limpio` | Camino feliz, rendimiento de ingesta |
| `inventario_dificil` | Umbral de `missed_cycles`, filtro de máscara |
| `portal_salida` | Clasificación de dirección |
| `portal_dudoso` | Que NO se dispare la alarma |
| `vecino_ruidoso` | Filtro de máscara |
| `red_caida` | Buffer y vaciado con retroceso |
| `avalancha` | Contrapresión y límites de memoria |

## Portal antihurto

Con `PORTAL_ENABLED=true` el borde publica cada salida con confianza suficiente
en `traza/{TRAZA_LOCATION_CODE}/portal` con QoS 1, sin pasar por el buffer: el
presupuesto es de 800 ms extremo a extremo y una alarma que suena cuando la
persona ya salió no sirve de nada. La lectura va igualmente al buffer para el
registro histórico.

Si no hay broker —o se cae— el publicador usa `POST /api/v1/ingest/portal-event`
como respaldo. Es más lento y no se compromete al presupuesto, pero es eso o
quedarse sin antihurto.

```bash
PORTAL_ENABLED=true SIMULATOR_SCENARIO=portal_salida npm run dev
```

## Observabilidad

- `GET :9100/health` — estado, profundidad del buffer y lecturas del último minuto
- `GET :9100/metrics` — formato Prometheus

La configuración de recolección y las seis reglas de alerta están en
`infra/prometheus/`. Verificado con un Prometheus real: objetivo en `up`, las
métricas de `docs/07` §9 presentes y las reglas evaluando.

La métrica más útil es `traza_edge_reads_dropped_total{stage="epc_mask"}`. Si
sube de golpe, o el vecino instaló RFID, o entró mercadería sin tarar.

En un portal, la que importa es `traza_edge_portal_events_total`. Un
`result="suppressed"` mucho mayor que `result="published"` significa que el
arco ve pasar gente pero no se atreve a clasificar el cruce: toca revisar la
colocación de las antenas antes que subir la potencia.

## Estado de implementación

| Pieza | Estado |
|---|---|
| Configuración validada con zod | Hecho |
| Pipeline de 5 etapas | Hecho |
| Simulador con los 7 escenarios | Hecho |
| Buffer SQLite + vaciado con retroceso | Hecho |
| Latido y métricas | Hecho |
| Apagado ordenado | Hecho |
| `LlrpAdapter` / `HttpWebhookAdapter` | Pendiente — tarea 2.7, depende de la 0.1 |
| Publicación MQTT del portal | Hecho — tarea 6.2 |
| Impresión ZPL | Pendiente |
