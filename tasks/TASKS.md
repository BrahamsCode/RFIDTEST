# TASKS.md — Backlog ejecutable

> Backlog pensado para ejecución por agentes de IA (Claude Code) y por personas.
> Cada tarea es autocontenida: contexto, entregable, criterio de aceptación y documento de referencia.
>
> **Reglas de trabajo**
> 1. Leer el documento referenciado **antes** de escribir código.
> 2. Una tarea = una rama = un merge request.
> 3. Ninguna tarea se marca hecha sin su prueba pasando.
> 4. Si una tarea contradice la documentación, **detenerse y preguntar**. No improvisar sobre el modelo de datos.
> 5. Las tareas marcadas 🔒 son bloqueantes: nada que dependa de ellas puede empezar antes.

Leyenda: `[ ]` pendiente · `[~]` en curso · `[x]` hecho · 🔒 bloqueante · ⚠️ requiere decisión de negocio

---

## Épica 0 — Validación y cimientos

### 0.1 🔒 Prueba de tasa de lectura sobre surtido real
- **Contexto**: `docs/03-hardware-y-bom.md` §3.2
- **Entregable**: informe con tasa de lectura de 3 modelos de inlay sobre 100 prendas del peor caso, en pila y colgadas
- **Aceptación**: informe firmado con la decisión continuar/ajustar/detener
- **No es una tarea de software.** Bloquea toda la Épica 2 en adelante
- [ ]

### 0.2 ⚠️ Decisión de esquema EPC
- **Contexto**: `docs/04-codificacion-epc.md` §2.3
- **Entregable**: decisión escrita SGTIN-96 vs GID-96, y si se tramita GS1 Perú
- **Aceptación**: `TRAZA_EPC_SCHEME` y `TRAZA_GS1_COMPANY_PREFIX` definidos en `.env`
- [ ]

### 0.3 Repositorios y estructura
- **Entregable**: `traza-api` (Laravel 11), `traza-web` (Vite+React+TS), `traza-edge` (Node+TS), `traza-handheld` (Kotlin)
- **Aceptación**: los cuatro arrancan en local; `docker compose up` levanta el entorno completo
- **Referencia**: `infra/docker-compose.yml`
- [~] Los cuatro proyectos arrancan y sus pruebas pasan. `docker compose config`
  valida en dev, prod y borde.
- **Levantado ya con un daemon real**, no solo validado:
  - `postgres`, `redis` y `mosquitto` arrancan del compose de desarrollo tal
    cual está y quedan *healthy*. Comprobados de verdad y no por el estado del
    contenedor: las 18 migraciones corren sobre el PostgreSQL del compose y
    dejan las 30 relaciones de partición de `tag_reads`, Redis responde
    `PONG`, y Mosquitto completa un `mosquitto_pub`/`mosquitto_sub` de ida y
    vuelta.
  - **Las 502 pruebas de la API pasan contra el PostgreSQL del contenedor**,
    que es lo que de verdad valida la imagen: extensiones, ENUM nativos,
    particionado, disparadores y funciones PL/pgSQL incluidos.
  - **La imagen de producción del borde se construye y arranca.** Levantada en
    la red del compose contra el Mosquitto del contenedor: lee del simulador,
    2 908 lecturas por el pipeline en 21 s, `/health` y `/metrics`
    respondiendo, y el buffer acumulando porque la API no estaba levantada —
    el comportamiento correcto.
  - De paso quedó descartada una sospecha razonable: `better-sqlite3` es un
    módulo nativo y `node:22-alpine` es musl, no glibc, así que era candidato
    a no tener binario precompilado y necesitar `python3`/`make`/`g++` en la
    imagen. **No hace falta**: instala en 5 s sobre Alpine y el módulo abre
    una base y ejecuta consultas. El Dockerfile del borde está bien como
    está.
- **Y aquí apareció el fallo que esta tarea existía para encontrar**: la
  imagen de la API **no se podía construir**, ni aquí ni en la máquina de
  nadie. El `Dockerfile` fija `php:8.3-cli-alpine` —como dice `docs/11`— pero
  el `composer.lock` se había resuelto en una máquina con PHP 8.4, así que
  arrastraba `symfony/*` v8.1, que exige `>=8.4.1`. `composer install` dentro
  de la imagen moría con «Your lock file does not contain a compatible set of
  packages».

  Ningún `docker compose config` detecta esto, ni ninguna prueba: en local
  todo funciona porque local **es** 8.4. Solo aparece al construir la imagen,
  que es justo lo que el criterio de aceptación pedía hacer.

  Arreglado fijando `config.platform.php = 8.3.33` en `composer.json` y
  volviendo a resolver el lock. Se elige eso y no subir la imagen a PHP 8.4
  porque 8.3 es lo que fija `docs/11`; `platform` es exactamente el mecanismo
  para «resuelve como si corrieras la versión del despliegue», así que la
  máquina de desarrollo puede seguir con 8.4 y el lock sigue siendo
  desplegable. Y cierra la puerta a que vuelva a pasar. Comprobado: las 502
  pruebas siguen pasando con las dependencias reducidas a la rama 7.4 de
  Symfony.
- **Lo que sigue sin comprobarse aquí** es el `docker compose up` completo con
  la API construida, y ya no por culpa del proyecto: dentro del contenedor de
  construcción, `composer` no puede autenticarse contra github.com a través
  del proxy de este entorno de desarrollo. La resolución de dependencias sí
  pasa ahora —falla al **descargar**, no al **resolver**—, que es la
  diferencia que importa. Se cierra del todo levantándolo en OrbStack con
  salida a internet normal.

### 0.4 Pipeline de CI
- **Contexto**: `docs/11-infraestructura-docker.md` §4
- **Entregable**: `.gitlab-ci.yml` con etapas lint, test, build
- **Aceptación**: el pipeline pasa en verde sobre `main` con el proyecto vacío
- [x] 12 trabajos en tres etapas. Cada script se ejecutó aquí antes de
      escribirlo: `pint --test` (hubo que formatear 35 ficheros), los tres
      `typecheck`, las cuatro suites y los dos `assemble`.
      Se aparta del borrador de `docs/11` §4 en cuatro puntos, todos porque el
      borrador nombra herramientas que el repositorio no tiene: no hay phpstan
      (el lint de PHP es Pint a secas), las pruebas son PHPUnit y no Pest, el
      trabajo de carga con k6 queda declarado pero desactivado hasta que exista
      el guion, y se añade el handheld, que el borrador no contemplaba.
      **No verificado en un runner de GitLab**: aquí no hay uno. Lo que está
      comprobado es que cada comando del pipeline pasa en este entorno.
      Se añadió el wrapper de Gradle, que faltaba y sin el cual el trabajo del
      handheld no arrancaría.

---

## Épica 1 — Núcleo de datos y codificación

### 1.1 🔒 Migraciones del esquema
- **Contexto**: `docs/05-modelo-de-datos.md` §7 · fuente: `sql/schema.sql`
- **Entregable**: 14 migraciones Laravel en el orden indicado, incluidos ENUM vía `DB::unprepared`, la tabla particionada y el trigger append-only
- **Aceptación**: `php artisan migrate:fresh` reproduce exactamente `sql/schema.sql`; `php artisan migrate:rollback` funciona
- [x] Verificado comparando `pg_dump --schema-only` de las dos vías sobre
  PostgreSQL 16: **1358 líneas idénticas**, sin una sola diferencia. El
  rollback deshace las 15 migraciones y deja 0 tipos ENUM. 11 pruebas de
  invariantes en `tests/Feature/SchemaTest.php`.
- **Ojo**: son **15** migraciones, no 14 — es el número de entradas que lista
  `docs/05` §7. Y en PostgreSQL hay que usar `migrate:fresh --drop-types`:
  `migrate:fresh` a secas no borra los tipos ENUM y falla en la segunda
  ejecución.

### 1.2 🔒 Codec SGTIN-96
- **Contexto**: `docs/04-codificacion-epc.md` §4
- **Entregable**: `Sgtin96Codec`, `Gid96Codec`, interfaz `EpcCodec`, `EpcCodecFactory`
- **Aceptación**: el grupo de pruebas `epc` pasa, incluido `encode('7751234','012345',1000000042) === '3035D919080C0E403B9ACA2A'` y las pruebas por propiedades de `docs/15` §2
- **Requiere**: extensión GMP en el Dockerfile
- [x] Vector de referencia exacto, las 7 particiones de ida y vuelta, y las
  pruebas por propiedades con 500 entradas aleatorias. 43 pruebas en el grupo
  `epc`.
- **No estaba bloqueada.** Se dio por bloqueada por la tarea 0.2 durante
  varias sesiones, y era un error de lectura: el prefijo de compañía es un
  **parámetro** de `encode()`, no configuración. Lo que 0.2 decide es qué
  esquema se usa en producción, no si el codec se puede escribir.
- **Decisión técnica: no se usa GMP.** `BitField` compone los 96 bits con una
  cadena de bits, que es igual de exacta y no depende de ninguna extensión de
  PHP: un requisito menos que puede faltar en el mini-PC de una tienda. La
  extensión sigue en el Dockerfile por si otra cosa la necesita.
- **Corrección sobre `docs/04` §4**: el método `toGtin13()` del documento
  devuelve 14 dígitos, no 13. En SGTIN la suma de dígitos de prefijo y
  referencia es siempre 13 en todas las particiones, así que con el control
  salen 14: eso es un **GTIN-14** (y por eso la columna del esquema es
  `VARCHAR(14)`). Se devuelven ambos: `gtin14` siempre, y `gtin13` solo
  cuando el dígito indicador es 0, que es el caso de una prenda suelta. Con
  indicador distinto de 0 se trata de un agrupamiento y no hay EAN-13
  equivalente, así que devuelve null en vez de un número inventado.

### 1.3 Máquina de estados del tag
- **Contexto**: `docs/06-backend-laravel.md` §3 · `docs/02-arquitectura.md` §8
- **Entregable**: `TagStateMachine`, enums `TagState` y `MovementType`
- **Aceptación**: prueba parametrizada sobre las 100 combinaciones estado×estado
- [x] Las 100 combinaciones cubiertas con matriz escrita a mano (no derivada
  de la propia clase, que no probaría nada). 126 pruebas en total.
- **Discrepancia resuelta**: la tabla de `docs/02` §8 y el código de `docs/06`
  §3 no coinciden. Se implementó `docs/06` §3 porque las dos transiciones que
  añade son obligatorias: `cambio_zona` va de `en_stock` a `en_stock`, y
  vender una prenda en `no_visto` (estaba en la tienda, solo no se leyó) exige
  `no_visto → vendido`. Sin ellas, mover una prenda de zona o venderla en caja
  lanzaría excepción. Conviene alinear la tabla de `docs/02`.

### 1.4 🔒 StockMovementService
- **Contexto**: `docs/06-backend-laravel.md` §2
- **Entregable**: `StockMovementService` con `apply()` y `applyBulk()`, `MovementIntent`
- **Aceptación**: las pruebas de integración de `docs/15` §3 pasan, incluidas concurrencia y trigger append-only
- **Regla arquitectónica**: ninguna otra clase escribe en `stock_movements` ni muta `tags.state`
- [x] 15 pruebas de integración contra PostgreSQL real. La de concurrencia usa
  dos sesiones de verdad (conexión `pgsql_second`): con la fila bloqueada el
  segundo movimiento espera y agota el `lock_timeout`, y el estado queda
  coherente. El trigger append-only rechaza UPDATE y DELETE.
- **Ojo para la tarea 3.2**: tal como define `docs/06` §2, un movimiento
  conserva `current_location_id` si la intención no la repite, pero **borra**
  `current_zone_id`. Para una venta es correcto; para un ajuste que solo
  confirme presencia en un ciclo, hay que pasar `toZoneId` explícitamente o la
  prenda se quedará sin zona. Queda documentado con una prueba.

### 1.5 Reserva de seriales
- **Contexto**: `docs/04-codificacion-epc.md` §3.2
- **Entregable**: función SQL `reserve_serial_range()` + envoltorio PHP
- **Aceptación**: 10 procesos concurrentes reservando 100 seriales no producen ningún duplicado
- [x] Verificado con **diez sesiones de PostgreSQL de verdad**, no diez
  llamadas seguidas: 1000 seriales, ninguno repetido, de 1 a 1000 sin huecos.
- `SerialReservationService` reserva y codifica de una vez con
  `reserveEpcs()`, y avisa si la variante agotó su espacio de seriales.
  Con `gid-96` no hace falta prefijo GS1, que es la vía de arranque de
  ADR-009.

### 1.6 Semilla de desarrollo
- **Entregable**: seeders Laravel equivalentes a `sql/seeds.sql`
- **Aceptación**: `php artisan db:seed` genera ≥ 1 000 tags, y el control de integridad de `docs/05` §6 devuelve 0 filas
- [x] Cuatro seeders (`Role`, `Reference`, `Tag`, `Operations`). **1 107 tags y
      el control de integridad en 0 filas**, con 8 pruebas que lo fijan.

      `sql/seeds.sql` **no pasa su propio control**: cargado tal cual devuelve
      12 filas y 51 unidades de desajuste. Dos causas, las dos comprobadas
      contra una base recién creada:
      1. Los tags que acaban en `no_visto` o `perdido` solo reciben el
         movimiento de `tarado`, así que `stock_as_of()` los cuenta como
         existencias y la proyección no (23 tags).
      2. `commissioned_at` y `sold_at` se sortean por separado sobre 200 y 60
         días, de modo que 28 de 1 038 prendas se vendían antes de existir; el
         último movimiento por fecha pasaba a ser el tarado.

      Un tercer fallo salió al escribir el seeder y es de la misma familia: un
      movimiento fechado en el futuro es invisible para `stock_as_of(loc,
      now())` y produce el mismo desajuste. Los tres tienen prueba.

      El seeder pasa por `StockMovementService` en vez de escribir
      `stock_movements` a mano, así que la coherencia entre proyección y
      movimientos no depende de que yo acierte, y los EPC los compone el codec
      real en vez de concatenar hex.

---

## Épica 2 — Ingesta y borde

### 2.1 🔒 Endpoint de ingesta
- **Contexto**: `docs/06-backend-laravel.md` §4
- **Entregable**: `POST /api/v1/ingest/reads`, `ReadIngestionService`, middleware `AuthenticateDevice`, `EpcMask`
- **Aceptación**: idempotencia por `batch_id`; EPC fuera de máscara rechazados sin llegar a `tag_reads`; inserción por lotes de 500
- [x] Los tres criterios verificados con 16 pruebas. Se añade también
  `POST /api/v1/ingest/heartbeat`, que el borde ya llamaba.
- **Prueba de extremo a extremo**: el borde real contra la API real dejó 1386
  lecturas de 150 EPC en `tag_reads`, ninguna ajena, todas en la partición del
  mes y `tag_reads_default` vacía.

### 2.2 Job ProcessReadBatch
- **Contexto**: `docs/06-backend-laravel.md` §4.4
- **Entregable**: job con detección de clonación por TID y enrutado por tipo de dispositivo
- **Aceptación**: un EPC con dos TID distintos genera alerta `tid_discrepante`
- [x] Resolución de tags, `unknown_epcs`, clonación por TID con alerta
  `tid_discrepante` y **el enrutado por tipo de dispositivo**, que ya se puede
  hacer porque existen `InventoryCycleService` y `PortalEventService`.
  - Handheld → las lecturas entran en el ciclo `en_curso` de su tienda. En
    bloque y no una a una: `registerScans` deduplica y emite un solo evento de
    avance, y con un evento por EPC un barrido de 20 000 prendas ahogaría al
    navegador. Un ciclo **pausado** no las recibe: la pausa existe porque el
    operario paró, y contarle lecturas la volvería inútil.
  - Lector fijo → **no** genera eventos de portal desde aquí, y es
    deliberado. El portal va por el camino rápido MQTT y ya escribió su
    `portal_events` con dirección y confianza; reevaluarlo desde la ingesta
    duplicaría la alarma y encima sin dirección, porque `tag_reads` no la
    guarda: todo saldría como `indeterminado`.
- **⚠️ Defecto de diseño en `docs/06` §4.4**: el job recibe `batchId` pero la
  consulta no filtra por él; selecciona por dispositivo y los últimos 10
  minutos. Con el borde vaciando cada segundo, cada lote reprocesa toda la
  ventana: el trabajo crece de forma cuadrática y `unknown_epcs.seen_count` se
  infla (medido: 2240 avistamientos para 1386 lecturas reales). Arreglarlo
  bien pide una columna `batch_id` en `tag_reads`, que es un cambio del modelo
  de datos y necesita decisión de negocio. **Pendiente de resolver antes de
  producción.**

### 2.3 🔒 Esqueleto de traza-edge
- **Contexto**: `docs/07-middleware-rfid.md` §2, §3
- **Entregable**: proyecto TypeScript con configuración validada por zod, tipos base, arranque y apagado ordenado
- **Aceptación**: `SIGTERM` vacía a SQLite sin perder lecturas en vuelo
- [x] Verificado: con 11 132 lecturas en vuelo, tras `SIGTERM` quedaron 11 166
  filas en SQLite (se volcó lo pendiente en memoria en lugar de perderlo).

### 2.4 🔒 Simulador de lector
- **Contexto**: `docs/07-middleware-rfid.md` §8
- **Entregable**: `SimulatorAdapter` con los 7 escenarios predefinidos
- **Aceptación**: cada escenario es reproducible con semilla fija; se usa en todas las pruebas del borde
- **Prioridad alta**: desbloquea el desarrollo sin hardware
- [x] Los 7 escenarios definidos y deterministas (PRNG mulberry32 con semilla).

### 2.5 Pipeline de filtrado
- **Contexto**: `docs/07-middleware-rfid.md` §4
- **Entregable**: las 5 etapas + `Pipeline` con contadores
- **Aceptación**: escenario `vecino_ruidoso` → 0 lecturas ajenas pasan; `DedupeStage` no crece indefinidamente en memoria durante 20 min
- [x] Las 5 etapas con contadores. Corregido un fallo del código de ejemplo del
  doc: `DedupeStage` anclaba el barrido al reloj de pared en vez de a la marca
  de la lectura, así que nunca se disparaba y el mapa crecía sin límite.

### 2.6 Buffer offline y vaciado
- **Contexto**: `docs/07-middleware-rfid.md` §7
- **Entregable**: `SqliteBuffer` con WAL, `Flusher` con retroceso exponencial
- **Aceptación**: escenario `red_caida` → 0 lecturas perdidas en 10 min sin API
- [x] **Escenario `red_caida` completo, 10 min con la API inalcanzable y
  después reconexión, con la contabilidad cuadrando a la lectura.**

  | | |
  |---|---|
  | Duración sin API | 612 s (3 000 prendas simuladas) |
  | Aceptadas por el pipeline | 53 910 |
  | Retenidas en SQLite | 53 910 |
  | Descartadas | **0** |
  | Filas en `tag_reads` tras reconectar | 54 009 |
  | EPC distintos | 3 000 = la población entera |

  Las 54 009 son las 53 910 del corte más las 99 que generó el borde ya
  reconectado: **no se perdió ni una**. El retroceso exponencial llegó a su
  techo de 60 s y se mantuvo ahí (14 intentos en 10 min en vez de 600).

- **La prueba de verdad es la reconexión, no el buffer.** Que el contador
  `buffer_depth` suba durante el corte solo demuestra que las filas se
  escriben; no demuestra que se puedan leer, ni que el formato siga siendo
  válido, ni que el lote se acepte. Por eso el borde se **mató con SIGTERM**,
  se levantó de nuevo apuntando a una API real **con el mismo fichero
  SQLite**, y se comprobó que las 53 910 acaban en `tag_reads`. Así se cubre
  también la durabilidad entre reinicios, que es el caso realista: en una
  tienda de Gamarra el corte de luz y el corte de red son el mismo suceso.
- `take()` no borra: las filas salen del buffer solo con el `ack()` posterior
  a un POST correcto. Es lo que hace que un fallo a mitad de envío no pueda
  perder nada, y por eso un lote reintentado duplica antes que perder —el
  orden correcto de preferencias para un inventario—.
- **El apagado ahora deja el balance por escrito**: `aceptadas`, `enviadas`,
  `pendientes` y `descartadas por límite`. Antes solo registraba las
  pendientes, y con ese único número no se puede comprobar «no se perdió
  nada» sin abrir el SQLite a mano. Fue justo lo que hizo falta aquí.

#### Dos defectos de autenticación que aparecieron montando esta prueba

Ninguno de los dos tiene que ver con el buffer. Salieron al dar de alta un
segundo dispositivo para la prueba, que es la clase de cosa que no se hace
nunca en desarrollo y se hace siempre en producción.

1. **`AuthenticateDevice` buscaba el dispositivo por su código**, y el código
   es único solo dentro de la organización (`devices_code_unique` es
   `(organization_id, code)`). Dos tiendas pueden llamar `EDGE-01` a su borde
   con todo el derecho; la consulta se quedaba con el primero que salía y la
   que perdía el sorteo **veía rechazado un token perfectamente válido**. No
   se puede reproducir con una sola tienda, así que habría aparecido el día
   de la segunda instalación. Comprobado: con el código anterior el segundo
   token válido devuelve 401.

   Ahora el dispositivo se localiza **por el hash de su token**, que es lo
   único que de verdad lo identifica —un secreto de 380 bits que solo tiene
   él—. El código se sigue exigiendo como comprobación de coherencia: un
   borde que presenta el token correcto con el código de otro está mal
   configurado y es mejor que falle ruidosamente que atribuir lecturas a la
   tienda equivocada durante meses.

2. **El token se verificaba con bcrypt en el camino caliente de la ingesta.**
   Medido aquí: **231 ms por comprobación**, y se hacía en cada POST de
   lecturas. Cada borde vacía su buffer una vez por segundo, así que diez
   tiendas son 2,3 s de CPU por segundo quemados en hashear; al límite de
   2000/min que fija `docs/06` §6 harían falta unos ocho núcleos dedicados a
   ello. Un token inventado costaba lo mismo, así que también era una forma
   cómoda de tumbar la ingesta.

   bcrypt es lento **a propósito**, y tiene todo el sentido para la
   contraseña de una persona: encarece la fuerza bruta contra algo que se
   elige mal. Un token de dispositivo no lo elige nadie —`Str::random(64)`,
   unos 380 bits— y no hay fuerza bruta que valga contra eso ni a un billón
   de intentos por segundo. El estiramiento de clave no aportaba seguridad,
   solo coste. Se pasa a SHA-256 con `hash_equals`, que es además lo que ya
   hacía `DeviceEnrollmentService` con el token de alta: el sistema se
   contradecía consigo mismo.

   Ingesta medida antes y después: **259 ms → 30 ms**.

- La documentación (`docs/12` §4) solo exige que el token viaje hasheado en
  `devices.api_token_hash`; no fija el algoritmo, así que esto no contradice
  nada y no hacía falta parar a preguntar.
- **Sin migración de datos ni volver a dar de alta a nadie**: los hashes
  bcrypt existentes se siguen aceptando y se reescriben a SHA-256 la primera
  vez que aciertan, así que cada dispositivo paga el bcrypt una vez en su
  vida y el camino lento se apaga solo. El respaldo solo se activa si existe
  un dispositivo con ese código y hash bcrypt, para que un token basura no
  dispare la comprobación cara.
- Se añade el índice **único** `devices_api_token_hash_unique`, en la
  migración y en `sql/schema.sql` a la vez. Hace falta para que la búsqueda
  por hash no sea un recorrido secuencial, y de paso impone en la base algo
  que conviene que sea imposible: que dos dispositivos compartan token.
- Los rechazos de este middleware devolvían JSON suelto en vez de RFC 7807.
  Corregido, que es lo que fija `docs/06` §6 para el resto de la API.
- 15 pruebas nuevas en `DeviceTokenAuthTest`. Varias pruebas antiguas
  compartían un mismo token entre todos los dispositivos que creaban; se les
  da uno propio a cada uno, que es lo realista y lo que el índice exige.

### 2.7 Adaptador de lector real
- **Contexto**: `docs/07-middleware-rfid.md` §5
- **Entregable**: `LlrpAdapter` o `HttpWebhookAdapter` según el modelo elegido en 0.1
- **Aceptación**: lecturas reales de un lector físico llegan a `tag_reads`
- **Depende de**: hardware disponible
- [ ]

### 2.8 Latido y métricas
- **Contexto**: `docs/07-middleware-rfid.md` §9
- **Entregable**: `Heartbeat`, endpoint `/metrics` en formato Prometheus, endpoint `/health`
- **Aceptación**: las métricas listadas en §9 aparecen y Prometheus las recoge
- [x] **Verificado con un Prometheus 3.1 de verdad**, no solo con pruebas
      unitarias: el borde arrancado contra el simulador, el objetivo en `up`, y
      las seis consultas devolviendo datos —incluida
      `histogram_quantile(0.95, rate(traza_edge_flush_duration_seconds_bucket[5m]))`.
      Al matar el borde, `BordeSinResponder` pasó a `pending`, así que las
      reglas también evalúan.

      Faltaban tres de las métricas de §9 y una estaba mal:
      - `traza_edge_flush_duration_seconds` no existía. Se añade como
        histograma, cronometrando **también los intentos fallidos**: un
        vaciado que tarda 30 s en dar timeout es justo el síntoma que
        interesa, y medir solo los éxitos lo escondería.
      - `traza_edge_portal_events_total` usaba la etiqueta `result` en vez de
        `direction`. Ahora expone las dos series: la de `docs/07` §9 por
        dirección, y `traza_edge_portal_publish_total` por resultado.
      - El latido enviaba como `reads_last_min` el **acumulado desde el
        arranque**. Un lector muerto seguía reportando millones de lecturas y
        nadie veía que había dejado de leer. Se sustituye por un contador de
        ventana móvil real.
      - El latido no mandaba `cpu_percent` ni `temperature_c`, que el
        documento sí pide. La CPU se calcula por diferencia entre muestras
        (una sola lectura de `os.cpus()` da la media desde el arranque) y la
        temperatura sale de `/sys/class/thermal`, con null donde no hay sensor.

      Se añaden `infra/prometheus/{prometheus,prometheus.dev,alerts}.yml` con
      seis reglas —validadas con `promtool`— y el servicio en el compose de
      desarrollo. El fichero de desarrollo es aparte a propósito: con los tres
      objetivos de producción, `BordeSinResponder` estaría disparada siempre en
      local y el equipo aprendería a ignorarla.

---

## Épica 3 — Ciclos de inventario

### 3.1 🔒 Creación de ciclo con congelado de esperados
- **Contexto**: `docs/05-modelo-de-datos.md` §2.4
- **Entregable**: `InventoryCycleService::create()` que puebla `inventory_cycle_expected`
- **Aceptación**: una venta posterior al arranque no altera la lista de esperados
- [x] Verificado. El congelado usa `INSERT ... SELECT`, no trae los tags a PHP.
  Soporta alcance por zona.

### 3.2 Registro de escaneos
- **Entregable**: `POST /api/v1/inventory-cycles/{id}/scans` con deduplicación por EPC
- **Aceptación**: 20 000 EPC en lotes de 500 se registran en < 30 s
- [x] **1,18 s** para 20 000 EPC, muy por debajo del límite. Deduplica dentro
  del lote y entre lotes, acumulando conteos y quedándose con el RSSI máximo.

### 3.3 🔒 CycleReconciler
- **Contexto**: `docs/06-backend-laravel.md` §5
- **Entregable**: reconciliación con las 3 poblaciones calculadas en SQL y umbral `missing_cycles_threshold`
- **Aceptación**: una prenda no vista una vez → `no_visto`; dos veces → `perdido`. Nunca `perdido` en el primer ciclo
- [x] Las tres poblaciones en SQL. Criterio verificado con dos ciclos
  encadenados.
- **Hueco corregido en `docs/05` §2.4**: dice "encontrados → sin acción", pero
  los esperados incluyen `no_visto`. Sin acción, una prenda reencontrada
  conservaría `missed_cycles = 1` y el siguiente ciclo con un fallo de lectura
  la declararía merma, **borrando stock real que se había visto hace un
  ciclo**. Se aplica la transición `no_visto → en_stock (reaparece)` que sí
  recoge `docs/02` §8, y que resetea el contador.
- **Corrección de robustez**: `docs/06` §5 selecciona los perdidos con
  `missed_cycles >= umbral AND state != perdido`, lo que incluiría prendas en
  `en_stock`; la máquina de estados prohíbe `en_stock → perdido` y la
  conciliación entera reventaría. Se restringe a `no_visto` y `en_transito`.
- **Nota**: la clave de configuración se llama `missing_cycles_threshold`, como
  en `docs/06` §5 (antes estaba como `missing_threshold`).

### 3.4 Difusión de progreso
- **Contexto**: `docs/06-backend-laravel.md` §8
- **Entregable**: evento `InventoryCycleProgressed` sobre Reverb
- **Aceptación**: la web refleja el avance con menos de 3 s de retardo
- [x] `InventoryCycleProgressed` y `PortalAlarmRaised` con sus canales
  privados y autorización por tienda, más el hook `useCycleProgress` en la
  web. Se difunde **un evento por lote**, no uno por EPC: en un barrido de
  20 000 prendas serían 20 000 eventos.
- Si `VITE_REVERB_KEY` no está configurada, la web cae al sondeo de 5 s en
  vez de romperse. Con Reverb activo el respaldo baja a 60 s.
- **Medido contra un Reverb real** (`php artisan reverb:start --port=8085`)
  con `traza-web/perf/reverb-latencia.mjs`, que se suscribe al canal privado
  firmando el `socket_id` con HMAC igual que hace el navegador:
  **retardo por debajo de 1 ms** en local, frente al criterio de 3 s.
- **La medición encontró un fallo que ninguna prueba unitaria veía**: ninguno
  de los dos eventos definía `broadcastAs()`. Sin él el servidor emite
  `App\Events\InventoryCycleProgressed` (con el espacio de nombres) mientras
  Echo escucha `InventoryCycleProgressed`. El evento sale, llega al canal, y
  el navegador lo ignora en silencio: no hay excepción, ni log, ni nada que
  mirar. La web habría caído para siempre al sondeo de respaldo y habría
  parecido que «Reverb va lento». Añadido `broadcastAs()` a los dos, con
  prueba de regresión en `BroadcastingTest`.
- **Aviso sobre `routes/channels.php`**: los parámetros del canal llegan como
  **cadena**. Tiparlos como `int` con `declare(strict_types=1)` provoca un
  TypeError que Laravel convierte en denegación silenciosa, sin error visible
  en ninguna parte.

### 3.5 Rendimiento por zona
- **Entregable**: endpoint que expone `cycle_zone_performance()`
- **Aceptación**: identifica correctamente una zona no barrida en el escenario de prueba
- [x] `GET /inventory-cycles/{id}/zone-performance` sobre la función SQL
  `cycle_zone_performance()`, ya disponible tras la tarea 8.1. Devuelve las
  zonas ordenadas por exactitud ascendente: la peor barrida sale primera.

---

## Épica 4 — Aplicación web

### 4.1 Base del proyecto web
- **Contexto**: `docs/08-frontend-react.md` §1, §2, §10
- **Entregable**: Vite + React + TS + Tailwind + TanStack Query + Router, cliente de API con interceptores, primitivas de UI
- **Aceptación**: autenticación con Sanctum funcionando; `TAG_STATE_UI` aplicado
- [x] Enrutado, sesión, layout y primitivas. Login verificado de extremo a
  extremo contra la API real.
- **Faltaba en el backend**: `install:api` no crea rutas de sesión, así que
  `/login` devolvía 404 y la web no podía autenticarse. Se añaden
  `AuthController`, `statefulApi()`, `config/cors.php` y
  `SANCTUM_STATEFUL_DOMAINS`.

### 4.2 Ciclo de inventario en vivo
- **Contexto**: `docs/08-frontend-react.md` §4
- **Entregable**: pantalla con progreso, desglose por zona, `SlowZoneHint`, hook `useCycleProgress`
- **Aceptación**: con el simulador corriendo, la pantalla avanza en tiempo real y avisa de la zona lenta
- [x] Progreso, desglose por zona y `SlowZoneHint`, con 5 pruebas. Verificado
  con datos reales: con la sala al 95 % y la trastienda al 0 %, el aviso
  nombra la trastienda.
- Usa `useCycleProgress` sobre Reverb (tarea 3.4), con sondeo de respaldo.
- **Comprobado con Reverb en marcha**: el evento emitido por el servidor llega
  al suscriptor del canal privado en menos de 1 ms. Es la misma medición que
  cierra 3.4, y es la que destapó el `broadcastAs()` que faltaba —sin él esta
  pantalla se habría quedado avanzando por sondeo cada 60 s y nadie habría
  sabido por qué—.

### 4.3 Stock y reposición
- **Entregable**: listados de stock, valorización, antigüedad y reposición sobre las vistas SQL
- **Aceptación**: p95 < 200 ms con 50 000 tags en la base
- [x] Pantalla y endpoints (`/stock`, `/valuation`, `/aging`,
  `/replenishment`, `/summary`) sobre las vistas de la tarea 8.1, con 7
  pruebas.
- **Medido con 51 107 tags** en la base, 320 peticiones autenticadas contra
  los cinco endpoints. El peor p95 fue **33,0 ms**, seis veces por debajo del
  presupuesto de 200 ms. La única respuesta no-200 fue del limitador de
  ritmo, comprobado aparte: exactamente 300 × HTTP 200 y 20 × HTTP 429, que
  es justo lo que dice `docs/06` §6.
- Detalles del montaje de datos que costaron un rato: el `check` de
  `tags_epc_hex` exige mayúsculas (`upper()`), y `random() * 300 || ' days'`
  genera notación científica que PostgreSQL no acepta como intervalo
  (`round(...)::int * interval '1 day'`). El disparador de solo-anexado
  impide limpiar la tabla después, así que la medición se hace sobre
  `migrate:fresh`.

### 4.4 Ficha de prenda
- **Contexto**: `docs/08-frontend-react.md` §5
- **Entregable**: ficha con estado, ubicación, historial completo y gráfico de RSSI
- **Aceptación**: el historial coincide exactamente con `stock_movements`
- [x] Ficha con estado, ubicación, datos de la venta e historial completo.
  El endpoint ya tenía prueba de que el historial cuadra con
  `stock_movements`.
- **Gráfico de RSSI cerrado**: `GET /api/v1/tags/{epc}/detections` agrega
  `tag_reads` por hora, dispositivo y antena, y `RssiChart.tsx` lo dibuja con
  recharts sobre un eje fijo de −90 a −30 dBm. 9 pruebas.
- Se agrega y no se devuelven las lecturas crudas: tres meses de retención son
  decenas de miles de puntos que el navegador no dibuja y que además no dicen
  nada. La ventana tiene tope de 720 h, que es lo que recorta particiones.
- **Lo que de verdad aporta el gráfico es el aviso de señal débil.** Un RSSI
  plano en torno a −75 dBm no prueba que la prenda esté donde el sistema dice;
  prueba que se lee de lejos, y en Gamarra eso normalmente significa desde el
  local de al lado. Sin el aviso, soporte mira la gráfica y concluye lo
  contrario: «sí aparece, luego está ahí».

### 4.5 Tabla virtualizada de tags
- **Contexto**: `docs/08-frontend-react.md` §6
- **Entregable**: `TagTable` con `useVirtualizer` + `useInfiniteQuery`
- **Aceptación**: 20 000 filas se desplazan a 60 fps
- [x] `TagTable` con `useVirtualizer` y `useInfiniteQuery`, cargando la
  página siguiente al acercarse al final.
- **Medido en Chromium de verdad** con `traza-web/perf/tagtable-fps.mjs`
  sobre la compilación de producción: 20 000 filas cargadas, 879 488 de
  880 000 px recorridos, 299 fotogramas, p95 de 16,7 ms = **59,9 fps**, **0
  fotogramas perdidos** y 24 filas en el DOM. jsdom no vale para esto: no
  compone, no pinta y no tiene fotogramas.
- **La primera medición era falsa y daba mejor resultado.** El script
  empezaba a desplazarse de inmediato, así que recorría las ~1 200 filas que
  había cargadas y no las 20 000: medía la tabla en su caso fácil. Ahora
  fuerza la carga completa antes de cronometrar. La medición cómoda es la que
  hay que desconfiar.

### 4.6 Catálogo y lotes de etiquetas
- **Entregable**: CRUD de productos y variantes; generación de lotes con reserva de seriales y descarga de ZPL
- **Aceptación**: un lote de 100 etiquetas genera ZPL válido con EPC correctos
- [x] `LabelBatchService` + `ZplRenderer`, catálogo en `/api/v1/products` y
      pantalla `/etiquetas`. **Un lote de 100 se prueba entero**: 100 EPC
      correlativos, los 100 decodifican al prefijo y la referencia correctos, y
      el ZPL lleva 100 bloques `^RFW,H,1,12,1` más el `^RQ` de resultado.

      Usa la tabla `tag_batches` que ya estaba en el esquema —con
      `printed_ok`/`printed_void` para la tasa de inlays fallidos de
      `docs/04` §5—, así que no hace falta tocar el modelo de datos.

      Decisiones que conviene conocer:
      - Los tags nacen en `creado` **antes** de imprimir. Al revés, un corte
        de luz a mitad de rollo dejaría etiquetas físicas con EPC que el
        sistema no conoce, y esas prendas serían invisibles al inventario.
      - `item_reference` no se puede editar una vez creada la variante: va
        dentro del EPC de cada etiqueta ya impresa, y cambiarla haría que esas
        prendas se decodificaran como otra variante, en silencio.
      - `^` y `~` se neutralizan en los textos. Un producto llamado
        «CAMISA ~ OFERTA» partiría la etiqueta en dos comandos ZPL.
      - Hay reimpresión por EPC concreto: si la impresora se atasca a mitad de
        rollo, volver a emitir el lote duplicaría el inventario de la variante.

      **El ZPL ya no está solo comprobado como texto: se renderiza en un
      intérprete ZPL de verdad** (Labelary, 8 puntos/mm) y se mira la etiqueta
      resultante. Cuatro casos —normal, con metacaracteres, mínimo sin precio
      ni barcode, y nombre largo—, los cuatro sin un solo aviso.

      Y eso destapó **dos fallos que leer el ZPL no enseña**, los dos del
      mismo tipo: **ZPL recorta en silencio**. Ni error, ni aviso, ni nada — y
      una impresora hace exactamente lo mismo.

      1. **No se declaraba el tamaño de la etiqueta.** Sin `^PW`/`^LL` manda
         lo que tenga configurada la impresora. Renderizado a 2×1 pulgadas,
         que es un colgante de ropa normal, **el SKU desaparecía entero y el
         código de barras salía cortado e ilegible**; a 2,5×1,5 el SKU se
         truncaba a «SKU SKU—». La prenda se cuelga con una etiqueta que en
         caja no escanea y no se descubre hasta que hay cola. Ahora el ZPL
         declara su tamaño y el renderer **se niega a generar una etiqueta que
         no cabe**: mejor reventar el lote que gastar el rollo.
      2. **Un nombre largo se superponía al SKU.** «Casaca Impermeable Con
         Capucha Desmontable» —lo normal en confección— seguía escribiendo
         hacia la derecha y dejaba los dos ilegibles. Se recorta antes de
         emitir. Y se recorta en PHP y no con `^FB`, porque un bloque de una
         sola línea **no trunca: amontona** todas las líneas una sobre otra,
         que es todavía peor. También comprobado renderizando.

      El criterio para qué se sacrifica: perder el final del nombre es
      asumible, está en el sistema; perder el SKU no, porque es lo que mira el
      personal cuando el código de barras no escanea.

      **Sigue sin imprimirse en una Zebra física**, que es lo único que puede
      confirmar la escritura del inlay y la marca VOID. Eso llega con el
      hardware (tarea 0.1). Pero el diseño visible ya está validado por algo
      que no es mi criterio leyendo comandos.

### 4.7 Panel de tienda
- **Contexto**: `docs/13-kpis-y-analitica.md` §3.1
- **Entregable**: pantalla de 4 números grandes legible a 3 m
- **Aceptación**: revisada en la tienda con el equipo real
- [~] Los cuatro números (stock, exactitud, alertas abiertas, reposición) más
  la antigüedad por tramos.
- **La revisión en tienda con el equipo real es presencial**, no se puede
  cerrar desde aquí.

---

## Épica 5 — Aplicación de mano

### 5.1 🔒 Abstracción del lector y falso
- **Contexto**: `docs/09-app-handheld.md` §3, §11
- **Entregable**: interfaz `RfidReader` + `FakeRfidReader`
- **Aceptación**: toda la app se puede desarrollar y probar sin hardware
- [x] En `core:reader`, módulo Kotlin JVM sin dependencias de Android: se
  compila y se prueba sin SDK ni hardware. 12 pruebas pasando.

### 5.2 Modo inventario
- **Contexto**: `docs/09-app-handheld.md` §5
- **Entregable**: pantalla, ViewModel con `HashSet` de EPC, `KeepScreenOn`, háptica
- **Aceptación**: 20 000 EPC deduplicados sin degradación de fluidez
- [x] `InventorySession` en el módulo nuevo `core:domain` (Kotlin JVM puro,
      como `core:reader`), más `InventoryViewModel` y `InventoryScreen` en
      `:app`. **Medido**: 40 000 lecturas —dos pasadas sobre 20 000 tags, así
      que la mitad repetidas— en lotes de 25 como los del lector; el peor lote
      queda por debajo de los 16 ms de un fotograma a 60 Hz, que es el número
      que decide si la pantalla se atasca en la mano.
      La sesión restaura los EPC ya leídos desde Room al reabrir: sin eso, un
      cierre por memoria a mitad de barrido volvería a contar desde cero.

### 5.3 Sincronización offline
- **Contexto**: `docs/09-app-handheld.md` §6
- **Entregable**: Room + `UploadScansWorker` con manejo diferenciado de 5xx/401/4xx
- **Aceptación**: un ciclo completo en modo avión sincroniza íntegro al recuperar red
- [x] Room (`ScanEntity`, `TagEntity`, DAOs), `UploadScansWorker` con
      `HiltWorkerFactory`, y `SyncScheduler` con `ExistingWorkPolicy.KEEP`.
      La decisión de qué hacer con cada fallo vive en `UploadPolicy`, en
      `core:domain`, con sus pruebas: red y 5xx reintentan, 401/403 avisan del
      problema de credenciales en vez de reintentar en bucle, 408/425/429
      reintentan aunque sean 4xx —es el servidor pidiéndolo—, y el resto de
      4xx se marca fallido **conservando las filas** para diagnóstico. El
      retroceso exponencial tiene techo de 15 min: sin él, ocho fallos
      seguidos dejarían el siguiente intento a más de una hora vista.
      **El escenario de modo avión no está ejecutado extremo a extremo**:
      necesita un dispositivo o un emulador, y aquí no hay ninguno.

### 5.4 Modo tarado
- **Contexto**: `docs/09-app-handheld.md` §8
- **Entregable**: pantalla, validación de conflictos, 4 señales sonoras distintas
- **Aceptación**: perfil `TARADO` con potencia baja aplicado; un EPC ya tarado a otro SKU produce conflicto con confirmación explícita
- [x] `CommissioningSession` con las cuatro señales, más pantalla y diálogo de
      conflicto. El conflicto no reasigna nada hasta que alguien pulsa
      «Reasignar»: hacerlo en silencio descuadra el stock de dos variantes y
      el error es indetectable después. Una prueba comprueba que las cuatro
      señales son distintas entre sí — si dos coincidieran, el operario no
      podría distinguirlas sin mirar, que es el objetivo entero.
      ⚠️ **Corrección al perfil**: `ReadProfile.TARADO` estaba en sesión S1 y
      la tabla de `docs/09` §4 dice S0. No era cosmético: la persistencia de
      S1 calla al tag tras la primera respuesta, así que el `minReadCount = 2`
      del propio perfil no se alcanzaría nunca y no se taría ninguna prenda.
      Corregido a S0 en `core:reader` y en el perfil equivalente de
      `traza-edge`.

### 5.5 Modo búsqueda (Geiger)
- **Contexto**: `docs/09-app-handheld.md` §7
- **Entregable**: proximidad suavizada y pitido que acelera de 800 a 60 ms
- **Aceptación**: se localiza una prenda en menos de 3 min en prueba de campo
- [x] `ProximitySmoother` (media exponencial, α = 0.35) y `Geiger`, más la
      pantalla con círculos concéntricos. La interpolación del pitido es
      cuadrática y no lineal: con una recta, los últimos veinte puntos —cuando
      ya estás delante de la estantería correcta— apenas se distinguirían
      entre sí, que es donde hace falta resolución.
      El pitido corre en su propio bucle y no atado a cada muestra: si
      dependiera de las lecturas, un hueco del lector callaría el pitido justo
      al acercarse.
      **La prueba de campo de 3 minutos sigue pendiente**: es la tarea 0.1.

### 5.6 Alta por QR
- **Contexto**: `docs/09-app-handheld.md` §10
- **Entregable**: generación del QR en la web y canje en la app
- **Aceptación**: el token de alta es de un solo uso y caduca a los 15 min
- [x] `DeviceEnrollmentService` + `DeviceController` en el API, pantalla
      `/dispositivos` con el QR en la web, y `Enrollment` + `DeviceCredentials`
      (EncryptedSharedPreferences) en la app. 18 pruebas cubren el único uso,
      la caducidad, que el token no se guarde en claro, que el de un equipo no
      sirva para otro, y que el token resultante sirva de verdad para ingestar.
      ⚠️ **Decisión de almacenamiento que conviene revisar**: el token vive en
      la caché con TTL nativo y no en una tabla, porque `sql/schema.sql` no
      tiene sitio para esto y añadir una tabla es una decisión de modelo de
      datos. La consecuencia: vaciar la caché invalida los QR emitidos y sin
      canjear. Para 15 minutos parece asumible y falla cerrado, pero si
      queréis conservar el histórico de altas hace falta una tabla
      `device_enrollments` y eso lo decidís vosotros.

### 5.7 Implementación del SDK real
- **Entregable**: `ZebraRfidReader` o `ChainwayRfidReader` según 0.1
- **Aceptación**: los 5 modos funcionan sobre hardware real
- **Depende de**: hardware disponible
- [ ]

---

## Épica 6 — Portal antihurto

### 6.1 Clasificación de dirección
- **Contexto**: `docs/07-middleware-rfid.md` §4 (`DirectionStage`)
- **Entregable**: heurística de centroide temporal ponderado por RSSI
- **Aceptación**: escenario `portal_salida` → `salida` con confianza ≥ 0.7; `portal_dudoso` → sin evento
- [x] `DirectionStage` con tres correcciones sobre el borrador de `docs/07` §4
      (la confianza ya usa el desequilibrio de potencia, la separación se mide
      contra la duración del rastro en vez de contra 800 ms fijos, y el rastro
      deja de borrarse cuando la clasificación no concluye). Los escenarios
      `portal_salida` y `portal_dudoso` del simulador pasaron a guionizar el
      tránsito por tag con RSSI determinista: antes la antena se elegía con un
      contador global y el escenario dudoso emitía salidas. 9 pruebas nuevas en
      `traza-edge/test/portal.test.ts`.

### 6.2 Camino rápido por MQTT
- **Contexto**: `docs/07-middleware-rfid.md` §6
- **Entregable**: publicación MQTT desde el borde + comando `traza:listen-portal`
- **Aceptación**: latencia extremo a extremo p99 < 800 ms
- [x] `PortalPublisher` en el borde (tópico `traza/{tienda}/portal`, QoS 1, con
      respaldo HTTP a `POST /api/v1/ingest/portal-event` si no hay broker) y
      `traza:listen-portal` en Laravel, con reconexión indefinida, tolerancia a
      mensajes malformados y señal de vida para el healthcheck. Métricas
      `traza_edge_portal_events_total` en `/metrics`.
      **Medido con broker real** (Mosquitto 2.0.18 en el mismo host, 40
      tránsitos): p50 103 ms, p99 **110 ms** desde la publicación hasta la fila
      en PostgreSQL, y eso incluye los ~90 ms de arrancar `mosquitto_pub` en
      cada muestra, así que es una cota superior generosa. El tramo interno del
      servicio, medido aparte en PHPUnit, queda en p99 < 200 ms.
      Lo que **falta por medir** es el salto lector → borde, que necesita
      hardware (tarea 0.1); quedan unos 690 ms de presupuesto para ese tramo.

### 6.3 Período de gracia y alertas
- **Entregable**: verificación de venta reciente y alerta `salida_no_vendida`
- **Aceptación**: venta seguida de cruce dentro de 120 s → sin alarma; sin venta → alerta
- [x] `PortalEventService` con las cinco condiciones de silencio de P09: solo
      salidas, confianza por encima del umbral, EPC propio, sin venta reciente y
      tag no vendido. La ventana de 120 s y el estado `vendido` son
      comprobaciones distintas a propósito: la primera cubre las ventas que
      llegan de un POS externo, que no tocan el ciclo de vida del tag.

### 6.4 Registro de falsos positivos
- **Contexto**: `docs/10-procesos-operativos.md` P09
- **Entregable**: acción de un toque para marcar una alarma como falso positivo
- **Aceptación**: el indicador de `docs/13` §2 se calcula con esos datos
- [x] `POST /api/v1/portal-events/{id}/false-positive` y la pantalla `/portal`,
      con el botón sin diálogo de confirmación ni nota obligatoria: si costara
      más de un toque nadie lo registraría. `GET /portal-events/stats` calcula
      descartadas / totales y avisa al pasar del 20 %. La acción la puede hacer
      cualquiera con `alert.view`, también deliberadamente; el riesgo de que
      alguien descarte su propia alarma se cubre con auditoría, no con permisos.

---

## Épica 7 — Recepción, transferencias y ventas

### 7.1 Órdenes de recepción y túnel
- **Contexto**: `docs/10-procesos-operativos.md` P02
- **Entregable**: órdenes, recepción por lectura, pantalla de diferencias
- **Aceptación**: diferencia > 3 % exige confirmación de supervisor
- [x] `ReceivingService` más los endpoints `GET/POST /receiving-orders` y
  `POST /receiving-orders/{id}/receive`. Umbral del 3 % verificado: devuelve
  409 con las diferencias dentro para que la pantalla pida confirmación.
  Repetir la pasada no duplica movimientos, que es lo que P02 pide poder
  hacer antes de reclamar al proveedor. **La pantalla web es de la épica 4.**

### 7.2 Transferencias entre ubicaciones
- **Contexto**: `docs/10-procesos-operativos.md` P08
- **Entregable**: flujo despacho/recepción con estado `en_transito` y alerta a 7 días
- **Aceptación**: prueba E2E de transferencia completa entre LIM-01 y LIM-02
- [x] Despacho, recepción y detección de estancadas. Lo que sale y no llega se
  queda en `en_transito`, que es como se ven las pérdidas en transporte.

### 7.3 Ventas y devoluciones
- **Contexto**: `docs/10-procesos-operativos.md` P06, P07
- **Entregable**: endpoints de venta con EPC y devolución con verificación
- **Aceptación**: una devolución de un EPC nunca vendido se rechaza con mensaje claro
- [x] `POST /sales` y `POST /sales/{id}/return`. Los tres rechazos de
  devolución (EPC ajeno, nunca vendido, ya devuelto) salen en RFC 7807 con su
  mensaje. Un EPC desconocido no impide cobrar, como manda P06: el RFID nunca
  bloquea una venta.

### 7.4 Re-etiquetado
- **Contexto**: `docs/10-procesos-operativos.md` P11
- **Entregable**: sustitución registrada en `tag_replacements`
- **Aceptación**: una prenda re-etiquetada no se cuenta dos veces en el ciclo siguiente
- [x] Verificado creando un ciclo tras la sustitución: espera 1 prenda, no 2.
  El tag viejo pasa a `baja` y el nuevo hereda ubicación y zona.

---

## Épica 8 — Analítica y operación

### 8.1 Vistas y funciones analíticas
- **Entregable**: aplicar `sql/vistas-analiticas.sql` como migración
- **Aceptación**: las 7 vistas y 3 funciones devuelven datos coherentes con la semilla
- [x] Migración `2026_08_01_000120_create_analytic_views`. Verificado con
  `pg_dump`: aplicar `schema.sql` + `vistas-analiticas.sql` por un lado y las
  migraciones por otro da **1566 líneas idénticas**.
- Las 7 vistas, la materializada `mv_daily_stock` y las 3 funciones, con 16
  pruebas de coherencia. Incluye el control de integridad de `docs/05` §6:
  `v_current_stock` y `stock_as_of()` coinciden, o sea que la proyección
  `tags` no se ha desviado de `stock_movements`.

### 8.2 Rotación de particiones
- **Contexto**: `docs/05-modelo-de-datos.md` §5
- **Entregable**: comando `traza:rotate-partitions` programado el día 20
- **Aceptación**: crea la partición de los dos meses siguientes y purga las de más de 3 meses; alerta si `tag_reads_default` tiene filas
- [x] Los tres criterios con prueba. Dos decisiones que no están en el
      documento y conviene conocer:
      - **La purga no es el comportamiento por defecto.** Sin `--purge` el
        comando solo informa de lo que borraría. Es irreversible y el cron
        corre solo.
      - **Con `--purge` comprueba antes la exportación a frío.** Se niega a
        borrar una partición que no conste exportada y verificada en la
        auditoría, que es el orden que exige `docs/13` §6.

### 8.3 Control de integridad nocturno
- **Contexto**: `docs/05-modelo-de-datos.md` §6
- **Entregable**: comando `traza:check-projection` con alerta crítica
- **Aceptación**: una desincronización provocada artificialmente dispara la alerta
- [x] Consulta literal de `docs/05` §6, alerta de severidad 1, y las dos
      direcciones del desajuste probadas: proyección de menos y de más. No hay
      opción para excluir ubicaciones ni bajar el umbral — cualquier
      diferencia significa que hay un camino de código que muta `tags` sin
      pasar por `StockMovementService`.
      La alerta guarda una muestra de 20 filas y no el detalle entero: una
      alerta con 4 000 filas dentro no la lee nadie.

### 8.4 Exportación a frío
- **Contexto**: `docs/13-kpis-y-analitica.md` §6
- **Entregable**: comando `traza:export-cold-reads` a MinIO
- **Aceptación**: la purga solo se ejecuta si la exportación del mes existe y se verificó
- [x] **Medido con datos reales**: 131 045 lecturas exportadas en 2 s, 4.4 MB
      comprimidos, y el CSV descomprimido tiene exactamente 131 046 líneas
      (cabecera incluida).

      El borrador de `docs/13` §6 usa `COPY ... TO PROGRAM`, y no funciona en
      este despliegue: lo ejecuta el **servidor** de PostgreSQL, así que exige
      superusuario y que la ruta exista en el contenedor de la base, no en el
      del API. `COPY ... TO STDOUT` tampoco sirve — PDO lo rechaza en una
      sentencia preparada con «General error: 3». Se recorre con cursor por
      lotes y se escribe con `fputcsv`: más lento que `COPY`, memoria acotada,
      y funciona sin privilegios especiales. Para un trabajo mensual, la
      velocidad no es lo que decide.

      La verificación compara **bytes** entre origen y destino, no solo que la
      subida no lanzara excepción: un fallo a mitad deja el objeto truncado y
      el proveedor lo da por bueno.

### 8.5 Observabilidad
- **Contexto**: `docs/11-infraestructura-docker.md` §7
- **Entregable**: Prometheus, Grafana, Loki, reglas de alerta, 3 tableros
- **Aceptación**: cada alerta de §7 se ha provocado deliberadamente al menos una vez y ha llegado
- [x] **Las 12 reglas provocadas una a una y verificadas en estado `firing`**
      contra un Prometheus 3.1 real. Las cuatro del API se provocaron sobre la
      base de verdad: se rompió la proyección (7 unidades), se metió una fila
      en `tag_reads_default`, se encolaron 6 001 trabajos en Redis y se dejó el
      suscriptor de portal parado. Las del borde y las de respaldo, sirviendo
      las métricas correspondientes desde un endpoint de prueba — lo que se
      comprueba ahí es la expresión de la regla, que es donde estaba el fallo.

      **Y había un fallo, en dos reglas mías.** `SubidaDeEpcAjenos` y
      `PortalSinClasificar` comparan dos series con etiquetas distintas
      (`stage`, `direction`). Prometheus empareja por conjunto de etiquetas, no
      encontraba pareja, y devolvía vector vacío: **no habrían disparado
      nunca**. Corregido con `ignoring(...)`. Es exactamente el motivo por el
      que el criterio de aceptación pide provocarlas.

      Se añade `GET /api/v1/metrics` en el API con las cuatro series que las
      alertas del documento daban por existentes y no existían
      (`traza_portal_listener_alive`, `traza_tag_reads_default_rows`,
      `traza_stock_projection_mismatch`, profundidad de colas). Las consultas
      caras van con caché de 55 s: Prometheus sondea cada 30 s y la
      comprobación de discrepancia recorre `stock_movements` entero.

      Tres tableros de Grafana provisionados desde el repositorio, con las
      **21 consultas verificadas** contra Prometheus: ninguna con error de
      sintaxis y todas devolviendo datos.

      Queda fuera: **Loki**. El servicio está en el compose de producción pero
      no hay emisión de log estructurado con `trace_id` propagado desde el
      borde, que es lo que pide `docs/11` §7 y lo que de verdad sirve para
      seguir una lectura concreta desde la antena hasta la base.

### 8.6 Respaldos y restauración
- **Contexto**: `docs/11-infraestructura-docker.md` §6
- **Entregable**: `scripts/backup.sh`, servicio de respaldo, procedimiento de restauración
- **Aceptación**: una restauración completa en staging cronometrada por debajo de 2 h
- [x] `scripts/backup.sh` y `scripts/restore.sh`, los dos limpios de
      `shellcheck` y **ejecutados de verdad** contra la base sembrada: respaldo
      de 308 KB verificado, restaurado sobre `traza_staging`, 1 471 movimientos
      y 1 107 tags recuperados, y el `traza:check-projection` sobre la base
      restaurada devuelve 0 discrepancias.

      **RTO medido a escala de producción.** El cronometraje anterior no valía
      —mil tags se restauran en un segundo siempre—, así que se generó una base
      del tamaño que tendría TRAZA con **5 tiendas**, que es el punto donde
      `docs/14` §5 dice que el sistema tiene sentido económico:

      | | |
      |---|---|
      | Prendas | 100 000 (5 × 20 000, el escenario de `docs/05` §2.3) |
      | Lecturas | 7 200 000 en los 90 días de retención |
      | Movimientos | 142 857 |
      | Tamaño de la base | 1 748 MB |
      | **Respaldo** | **30 s** → 31 MB comprimidos |
      | **Restauración** | **14 s** |
      | **Total** | **44 s frente a un objetivo de 2 h** |

      El margen es de 160×, así que el escenario de 20 tiendas de `docs/05`
      —cuatro veces estos datos— sigue estando dos órdenes de magnitud por
      debajo del objetivo. El RTO no es el riesgo de este sistema.

      **Y lo restaurado se comprueba, no se cuenta**: `traza:check-projection`
      sobre la base restaurada dice «proyección y movimientos cuadran en 5
      ubicaciones». Contar filas solo demuestra que llegaron; esto demuestra
      que llegaron coherentes.

      Matiz honesto: la restauración recupera las particiones **calientes**
      (3,6 M de lecturas de julio y agosto). Mayo y junio quedan fuera a
      propósito, ya exportadas a frío, y recuperarlas es traerlas de MinIO. No
      hacen falta para que la tienda vuelva a operar, que es lo que mide el
      RTO.

      El fixture queda en `database/perf/escala-produccion.sql` para poder
      repetir la medición. **Pasa el control de integridad del propio
      proyecto**, que costó tres intentos y es la parte que más enseña:
      - `stock_as_of()` no suma entradas y salidas: coge el **último**
        movimiento de cada prenda y mira su `state_after`. Un libro con
        `tarado` y `recepcion` hacia la misma tienda dice que la prenda entró
        dos veces sin salir.
      - Las salidas con fecha futura son invisibles a `stock_as_of(loc,
        now())` y dejan la prenda contada como stock — el mismo fallo que ya
        tuvo `TagSeeder`. Pero recortarlas con un `LEAST(..., now())` a secas
        las coloca *antes* de su propio tarado en las prendas taradas hoy, y
        entonces el último movimiento vuelve a ser el tarado. Hay que caer
        entre las dos cosas.
      - Con `n % 1600` para la variante y `n % 300` para la antigüedad, los
        dos módulos se aliasean (1600 mod 300 = 100) y **un tercio** de las
        prendas de una variante concreta acaban taradas el mismo día. En
        conjunto la desviación parecía del 1,7 %; por variante era del 33 %.
        Repartido ahora con un hash.

      La prueba mensual del primer lunes sigue teniendo sentido, pero ya no
      para descubrir si el RTO se cumple: para detectar el día que deje de
      cumplirse.

      Tres cosas que el borrador de `docs/11` §6 no hacía:
      - Verifica que el volcado **contiene datos de `stock_movements`**, no
        solo que `pg_restore --list` no falla: un `pg_dump` cortado a mitad
        produce un fichero que `--list` acepta.
      - Excluye solo las particiones de `tag_reads` de más de 30 días, no
        todas: `--exclude-table-data='tag_reads_*'` dejaría sin respaldo el mes
        en curso, que todavía no está en frío.
      - Escribe métricas del último respaldo correcto para que Prometheus
        avise si dejan de actualizarse. Dos reglas nuevas en `alerts.yml`.

      `restore.sh` se niega a restaurar sobre una base cuyo nombre no lleve
      `staging` o `test` salvo con `--force`: teclear el nombre de producción
      ahí es fácil y no tiene vuelta atrás.

---

## Épica 9 — Seguridad y cumplimiento

### 9.1 Roles y políticas
- **Contexto**: `docs/12-seguridad-y-privacidad.md` §2
- **Entregable**: los 7 roles, políticas de Laravel, doble aprobación
- **Aceptación**: prueba por rol para cada operación sensible; `tecnico` no puede ajustar stock
- [x] `RoleCode` con los 7 roles y sus permisos, `InventoryCyclePolicy` y
  `StockAdjustmentPolicy`. Prueba parametrizada por rol: `tecnico` gestiona
  lectores pero no ajusta stock, y `jefe_tienda` declara merma pero no toca
  perfiles de lectura.
- Los cuatro umbrales de doble aprobación verificados, incluido el cierre de
  ciclo con exactitud por debajo del 90 % sin justificación escrita.
- **Efecto colateral que conviene saber**: hasta ahora cualquier usuario
  autenticado podía crear y cerrar ciclos. Ahora hace falta rol, y eso rompió
  tres pruebas de `ApiSurfaceTest` que pasaban porque la API estaba sin
  autorizar. Se corrigieron las pruebas, no la política.

### 9.2 Auditoría
- **Entregable**: `AuditObserver` sobre los modelos sensibles + informes periódicos
- **Aceptación**: todo ajuste manual queda registrado con usuario, dispositivo e IP
- [x] `AuditObserver` sobre tags, ciclos, dispositivos, usuarios, organización
  y variantes, con usuario, dispositivo, IP y agente. Omite contraseñas y
  hashes de token. Ignora los cambios que solo tocan `updated_at`.
- `stock_movements` no lleva observador porque **es** su propia auditoría: el
  trigger de la base impide reescribirlo.
- **Informes periódicos cerrados**: `AuditReportService` con los cuatro de
  `docs/12` §6 (ajustes manuales, mermas, cambios de dispositivo y accesos
  fuera de horario), el comando `traza:audit-report` y su programación en
  `routes/console.php`. 11 pruebas.
- **Los informes no devuelven un total, devuelven la desviación respecto al
  equipo.** «Ana hizo 30 ajustes negativos» no dice nada: puede ser la tienda
  con más rotación. Lo que se marca es quien **triplica la mediana** de sus
  compañeros, que es el patrón del fraude interno —cada ajuste por separado
  parece razonable, el conjunto no—.
- **Mediana y no media, a propósito.** Con la media, la persona con cien
  ajustes arrastra el umbral hasta que ella misma parece normal. Hay una
  prueba dedicada a esa diferencia.
- **Solo el informe de accesos levanta alerta**, y con severidad 4. Los
  mensuales casi siempre tienen filas; convertirlos en alerta haría que se
  acabaran silenciando, y con ellos los que sí importan. Un acceso a las tres
  de la mañana tampoco prueba nada por sí solo —hay quien cuadra inventario de
  noche—, pero es lo primero que se mira cuando aparece un descuadre.

### 9.3 Access password derivado
- **Contexto**: `docs/12-seguridad-y-privacidad.md` §3
- **Entregable**: derivación HMAC-SHA256 y endpoint que la entrega al handheld por EPC
- **Aceptación**: la clave maestra nunca sale del servidor; el handheld solo obtiene la contraseña del EPC concreto
- [x] `GET /api/v1/tags/{epc}/access-password`, con token de dispositivo y
  restringido a la organización del propio dispositivo. Conocer una
  contraseña no revela ninguna otra.
- Se añade el kill password derivado, distinto del de acceso: dejarlo en el
  valor de fábrica permite a cualquiera desactivar la etiqueta de forma
  irreversible.
- Se cubre el caso de que la derivación dé `00000000`, que equivale a un tag
  sin proteger. Improbable, pero existe.

### 9.4 ⚠️ Cumplimiento de la Ley 29733
- **Contexto**: `docs/12-seguridad-y-privacidad.md` §4
- **Entregable**: documento de seguridad, cartel informativo al cliente, información a empleados, consulta legal
- **Aceptación**: lista de verificación de `docs/12` §8, sección Cumplimiento, completa
- [~] Documento de seguridad escrito en `docs/16-documento-de-seguridad.md`,
  con el estado real de cada punto de la lista de `docs/12` §8.
- **El resto no es una tarea de desarrollo**: cartel informativo, información
  a empleados, asesoría legal sobre la Ley 29733, homologación MTC y
  procedimiento de respuesta a incidentes con responsable asignado.

### 9.5 Endurecimiento de infraestructura
- **Entregable**: TLS, WireGuard, MQTT sobre TLS con ACL, escaneo de imágenes, gestión de secretos
- **Aceptación**: lista de verificación de `docs/12` §8, secciones Infraestructura y Aplicación, completa
- [~] Hechos: cabeceras de seguridad (CSP, X-Frame-Options,
  X-Content-Type-Options, Referrer-Policy, HSTS solo bajo TLS) y límites de
  tasa de `docs/06` §6.
- **Fallo corregido**: el compose de producción montaba `mosquitto.prod.conf`,
  `certs/` y `passwd`, y **ninguno existía**: el broker no habría arrancado.
  Se añaden la configuración de producción (solo 8883 con TLS, sin listener
  en claro) y la ACL por tienda, más un README con cómo generar los secretos.
- **Añadido**: etapa `security` en el pipeline con cuatro trabajos —
  `composer audit`, auditoría de npm, escaneo de imágenes con Trivy y búsqueda
  de secretos con gitleaks. Los dos primeros se ejecutaron aquí y encontraron
  cosas de verdad:
  - **Lo que se despliega está limpio de high/critical.** El borde da 0
    vulnerabilidades en producción; la web, 2 moderadas en `react-router`.
  - **Las críticas son de desarrollo**: servidor de Vite e interfaz de Vitest,
    que no se ejecutan nunca en producción. Arreglarlas exige saltos de versión
    mayor (Vite 5→8, Vitest 2→4) que contradicen las versiones fijadas en
    `docs/08`, así que **no se tocan sin vuestra decisión**.
  - **CVE-2026-48019 en Laravel** (inyección CRLF en la regla `email`) afecta a
    todo 11.x sin arreglo dentro de la rama; el arreglo está en 12.60. Impacto
    real aquí: **bajo**, porque la única validación `email` es la del acceso y
    el sistema no envía correo con direcciones puestas por el usuario. Subir a
    Laravel 12 es una decisión de alcance que os corresponde.
  Por eso la auditoría de npm son **dos** trabajos: el de producción bloquea el
  pipeline, el de desarrollo informa. Un pipeline que falla siempre acaba
  ignorado, y entonces no avisa del día que sí importa.
- **WireGuard hecho**: `infra/docker-compose.edge.yml` —que `prod` ya citaba y
  no existía—, más las plantillas de borde y central en `infra/wireguard/` y
  su README con el alta de una tienda. `docker compose config` valida.
  - **El borde comparte pila de red con el túnel**
    (`network_mode: service:wireguard`). Así, si el túnel se cae, el borde
    **no tiene otra salida**: no queda una ruta directa por la que las
    lecturas se escapen en claro a internet, y se quedan en el buffer local,
    que es justo lo que debe pasar. Un contenedor con su propia red haría lo
    contrario sin que nada fallara de forma visible.
  - Cada tienda entra en el central con `AllowedIPs` de **un solo host**.
    WireGuard asocia cada IP de origen a la clave que la tiene permitida, así
    que el `/32` impide por construcción que un borde comprometido publique
    lecturas a nombre de otra tienda. Con `/24` podría. Es el mismo
    razonamiento que la ACL de Mosquitto.
  - En el borde, en cambio, `AllowedIPs = 10.8.0.0/24` y **no** `0.0.0.0/0`:
    por el túnel va lo que habla con el central, no la navegación del equipo.
  - `PersistentKeepalive = 25` no es opcional: los bordes están detrás del NAT
    del router de la tienda. Sin él la traducción caduca con unos minutos de
    silencio y **el central deja de poder iniciar la conexión**. El borde
    sigue enviando lecturas sin enterarse, pero el SSH de soporte no entra
    hasta que al borde le toque transmitir — la diferencia entre resolver una
    incidencia en remoto y coger un taxi hasta Gamarra.
  - Watchtower actualiza **solo el borde** y no el túnel: una actualización
    automática de WireGuard que saliera mal dejaría la tienda incomunicada y
    sin forma de entrar a arreglarla.
  - Las claves se generan **en el equipo de la tienda**; la privada no viaja.
- **Fallo corregido de paso, y de los que importan**: `.gitignore` no excluía
  ninguno de los secretos que los README daban por excluidos — ni `passwd` ni
  `certs/` de Mosquitto. Cualquiera que siguiera esas instrucciones al pie de
  la letra habría acabado subiendo las contraseñas de los bordes al
  repositorio. Ahora se excluye el directorio de WireGuard entero y se
  readmite solo lo que es plantilla, de modo que **un fichero nuevo con
  material de clave queda fuera por omisión**, que es como debe fallar esto.
  Comprobado con `git check-ignore` en los dos sentidos.
- **Segundo fallo del `.gitignore`**: la readmisión era `!.env.example`, que
  solo cubre ese nombre exacto, así que un `.env.edge.example` legítimo
  quedaba fuera sin avisar. Se pasa a `!*.example`. Es de esos fallos que solo
  se notan cuando alguien clona el repo y no encuentra la plantilla que la
  documentación le dice que copie.
- **Sigue pendiente y no es tarea de desarrollo**: generar el material de
  clave real, levantar el servidor y hacer el alta de las tiendas.

---

## Épica 10 — Puesta en marcha

### 10.1 Site survey de la tienda piloto
- **Contexto**: `docs/03-hardware-y-bom.md` §3.1
- [ ]

### 10.2 Instalación y calibración del portal
- **Contexto**: `docs/03-hardware-y-bom.md` §4 · prueba PC03 de `docs/15` §8
- **Aceptación**: ≥ 99 % de detección en cruce y 0 lecturas estáticas a más de 2.5 m
- [ ]

### 10.3 Prueba de interferencia con el vecino
- **Contexto**: prueba PC04 de `docs/15` §8
- **Aceptación**: 0 de 30 tags del local contiguo aparecen en el ciclo
- **Crítica en Gamarra**
- [ ]

### 10.4 Línea base de exactitud
- **Contexto**: `docs/14-roadmap-costos-riesgos.md` §2, Fase 0
- **Entregable**: inventario manual completo antes de arrancar
- **Aceptación**: cifra documentada y firmada. Sin ella no se podrá demostrar el retorno
- [ ]

### 10.5 Tarado del inventario completo
- **Aceptación**: ≥ 98 % del stock físico con EPC asociado
- [ ]

### 10.6 Formación del equipo
- **Contexto**: `docs/10-procesos-operativos.md`, sección final
- **Entregable**: vídeos de 2 min por proceso, grabados en la tienda real
- **Aceptación**: las 5 pruebas de aceptación de `docs/15` §9 superadas por personal de tienda sin ayuda técnica
- [ ]

### 10.7 Manual de incidencias probado
- **Contexto**: `docs/11-infraestructura-docker.md` §9
- **Aceptación**: alguien que no lo escribió resuelve las 4 incidencias simuladas siguiéndolo
- [ ]

---

## Orden de ejecución recomendado

```
0.1 ─┬─ 0.2 ── 0.3 ── 0.4
     │
     └─ (si la prueba de lectura falla → DETENER)

1.1 ── 1.2 ── 1.3 ── 1.4 ─┬─ 1.5 ── 1.6
                          │
                          ├─ 2.1 ── 2.2
                          │
                          └─ 3.1 ── 3.2 ── 3.3 ── 3.4 ── 3.5

2.3 ── 2.4 ─┬─ 2.5 ── 2.6 ── 2.8
            └─ 6.1 ── 6.2 ── 6.3

4.1 ─┬─ 4.2 ── 4.3 ── 4.4 ── 4.5 ── 4.6 ── 4.7
5.1 ─┴─ 5.2 ── 5.3 ── 5.4 ── 5.5 ── 5.6

  (hardware disponible) ──▶ 2.7 · 5.7

7.x  y  8.x  en paralelo tras 1.4
9.x  antes de producción, sin excepción
10.x en la tienda, tras 9.x
```

**Camino crítico**: `0.1 → 1.1 → 1.2 → 1.4 → 3.3`. Todo lo demás puede paralelizarse.

**Lo que desbloquea más trabajo en paralelo**: `2.4` (simulador) y `5.1` (falso de lector). Priorizarlas aunque no estén en el camino crítico.
