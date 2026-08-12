# 02 — Arquitectura del ecosistema

---

## 1. Vista de contexto (C4 nivel 1)

```
                            ┌───────────────────────────┐
                            │        PERSONAS           │
                            └───────────────────────────┘
   Operario de almacén   Vendedor de tienda   Jefe de tienda   Gerencia   Proveedor
          │                     │                   │             │           │
          │ tarado, recepción   │ venta, consulta   │ inventario  │ tableros  │ ASN
          ▼                     ▼                   ▼             ▼           ▼
   ╔══════════════════════════════════════════════════════════════════════════════╗
   ║                            TRAZA — Plataforma RFID                           ║
   ║   Identidad unitaria de prenda · Inventario en tiempo casi real ·            ║
   ║   Trazabilidad de movimientos · Antihurto · Analítica                        ║
   ╚══════════════════════════════════════════════════════════════════════════════╝
        │              │                │                 │              │
        ▼              ▼                ▼                 ▼              ▼
  ┌──────────┐  ┌────────────┐   ┌────────────┐   ┌─────────────┐  ┌──────────┐
  │ Lectores │  │ Impresora  │   │  POS /     │   │  ERP /      │  │ Pasarela │
  │  RFID    │  │  RFID      │   │  Caja      │   │ Contabilidad│  │  SUNAT   │
  │(mano/fijo)│  │(Zebra ZT)  │   │            │   │             │  │ (FE)     │
  └──────────┘  └────────────┘   └────────────┘   └─────────────┘  └──────────┘
```

### Sistemas externos

| Sistema | Relación | Protocolo |
|---|---|---|
| Lectores RFID fijos | TRAZA los controla y consume sus lecturas | LLRP / MQTT / HTTP webhook |
| Lector de mano (Android) | Ejecuta app TRAZA; sincroniza por API | HTTPS REST + cola offline |
| Impresora RFID | TRAZA le envía trabajos de impresión+codificación | ZPL sobre TCP 9100 |
| POS / caja | TRAZA le informa qué EPC salen vendidos | API REST bidireccional |
| ERP / contabilidad | TRAZA publica movimientos de stock valorizados | Exportación por lotes / API |
| Facturación electrónica | Fuera de alcance de TRAZA; se integra vía POS | — |

---

## 2. Vista de contenedores (C4 nivel 2)

```
┌─────────────────────── BORDE / TIENDA (on-premise) ────────────────────────┐
│                                                                             │
│  ┌───────────────┐    ┌──────────────┐    ┌────────────────────────────┐   │
│  │  Handheld     │    │ Lector fijo  │    │  traza-edge  (Node.js)     │   │
│  │  Android      │    │  portal /    │───▶│  · cliente LLRP            │   │
│  │  (Kotlin)     │    │  túnel       │    │  · pipeline de filtrado    │   │
│  │               │    └──────────────┘    │  · buffer SQLite offline   │   │
│  │  SQLite local │                        │  · publica a MQTT y API    │   │
│  └───────┬───────┘                        └────────────┬───────────────┘   │
│          │  HTTPS (sync por lotes)                     │                    │
└──────────┼─────────────────────────────────────────────┼────────────────────┘
           │                                             │  MQTT/TLS + HTTPS
           ▼                                             ▼
┌──────────────────────────── NUBE / SERVIDOR CENTRAL ────────────────────────┐
│                                                                              │
│   ┌──────────────┐    ┌─────────────────────────────────────────────┐        │
│   │  traza-web   │    │             traza-api (Laravel 11)          │        │
│   │  React 18    │───▶│  Módulos: Catálogo · Tags · Inventario ·     │        │
│   │  + Vite      │ API│  Movimientos · Recepción · Ventas · Alertas  │        │
│   │  + TanStack  │    │  Colas: Horizon · Broadcast: Reverb          │        │
│   └──────────────┘    └───────┬──────────────────────┬────────────────┘       │
│                               │                      │                        │
│                     ┌─────────▼────────┐   ┌─────────▼─────────┐             │
│                     │  PostgreSQL 16   │   │   Redis 7         │             │
│                     │  · OLTP          │   │  · colas          │             │
│                     │  · particiones   │   │  · caché          │             │
│                     │    de lecturas   │   │  · locks          │             │
│                     └──────────────────┘   └───────────────────┘             │
│                                                                              │
│   ┌──────────────┐   ┌──────────────┐   ┌──────────────┐                     │
│   │  Mosquitto   │   │   MinIO      │   │  Grafana +   │                     │
│   │  broker MQTT │   │  (imágenes)  │   │  Prometheus  │                     │
│   └──────────────┘   └──────────────┘   └──────────────┘                     │
└──────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Capas lógicas

```
┌────────────────────────────────────────────────────────────┐
│ 5. PRESENTACIÓN     web React · app Android · tableros      │
├────────────────────────────────────────────────────────────┤
│ 4. APLICACIÓN       casos de uso, orquestación, API REST    │
│                     Laravel: Controllers, Form Requests,     │
│                     Actions, Jobs, Events                    │
├────────────────────────────────────────────────────────────┤
│ 3. DOMINIO          Tag, Prenda, Movimiento, CicloInventario │
│                     reglas de negocio puras, sin framework   │
├────────────────────────────────────────────────────────────┤
│ 2. INTEGRACIÓN      middleware RFID, adaptadores de lector,  │
│                     ZPL, POS, ERP                            │
├────────────────────────────────────────────────────────────┤
│ 1. INFRAESTRUCTURA  PostgreSQL, Redis, MQTT, storage, red    │
└────────────────────────────────────────────────────────────┘
```

**Regla de dependencia**: las capas superiores dependen de las inferiores, nunca al revés. El dominio (capa 3) no conoce Eloquent ni HTTP. En la práctica esto se implementa con:

- Entidades de dominio como clases PHP planas en `app/Domain/`.
- Repositorios definidos como interfaces en `app/Domain/Contracts/` e implementados con Eloquent en `app/Infrastructure/Persistence/`.
- Los modelos Eloquent son *detalles de persistencia*, no el dominio.

> Pragmatismo: no se aplica hexagonal puro a todo el sistema. Los módulos CRUD (catálogo, usuarios, tiendas) usan Eloquent directamente. La separación estricta se reserva para **Inventario** y **Movimientos**, que es donde las reglas son complejas y cambiantes.

---

## 4. Decisiones arquitectónicas (ADR)

### ADR-001 — Middleware de borde separado del backend

**Contexto.** Los lectores fijos emiten miles de lecturas por segundo. LLRP es un protocolo binario sobre TCP con conexión persistente. Laravel-PHP en modo request/response no es adecuado para mantener sockets persistentes ni para absorber ese caudal.

**Decisión.** Un servicio independiente `traza-edge` en **Node.js**, desplegado en la tienda (Mac mini / mini-PC / Raspberry Pi 5), que:
- mantiene las conexiones LLRP con los lectores,
- ejecuta el pipeline de filtrado,
- almacena en SQLite si la red cae,
- publica eventos limpios a MQTT y a la API.

**Alternativas descartadas.**
- *Laravel Octane con workers de socket*: posible, pero mezcla responsabilidades y complica el despliegue.
- *Go en lugar de Node*: mejor rendimiento y binario único, pero el equipo tiene más experiencia en Node/TypeScript y ya construyó servidores MCP en Node. Revisable si el caudal lo exige.
- *Servicio del fabricante (Zebra FX Connect, Impinj ItemSense)*: reduce trabajo pero ata a un fabricante y encarece licencias.

**Consecuencias.** Un componente más que desplegar y monitorizar. A cambio, la tienda sigue capturando lecturas con internet caído, que es un requisito duro en Perú.

---

### ADR-002 — PostgreSQL como única base de datos operativa

**Contexto.** Se evaluó una base de series temporales (TimescaleDB, InfluxDB) para las lecturas crudas.

**Decisión.** PostgreSQL 16 con **particionado declarativo por rango de fecha** en `tag_reads`, más índices BRIN. Se mantiene abierta la extensión a TimescaleDB si el volumen lo justifica (es una extensión de Postgres, no una migración).

**Justificación.** El equipo ya opera PostgreSQL. Un volumen de 5 tiendas × 20 000 prendas × 4 ciclos/mes genera del orden de 10–50 M de lecturas crudas/mes: perfectamente manejable con particiones mensuales y purga a 90 días.

**Consecuencias.** Hay que implementar la rotación de particiones (job programado). Se documenta en `sql/schema.sql`.

---

### ADR-003 — El EPC es la clave del sistema; el TID es el testigo

**Decisión.** La tabla `tags` tiene `epc` como clave natural única. Se almacena además `tid` (nullable) capturado en el tarado. La discrepancia EPC/TID dispara una alerta de posible clonación.

**Consecuencias.** El tarado debe leer TID, lo que exige una operación de lectura adicional y ralentiza ligeramente el proceso. Se acepta.

---

### ADR-004 — Escritura de lecturas mediante ingesta por lotes, no fila a fila

**Decisión.** El middleware agrupa lecturas en lotes (por defecto 500 lecturas o 2 s, lo que ocurra antes) y los envía a un endpoint de ingesta que hace `COPY`/`insert` masivo dentro de una transacción, encolando después el procesamiento de dominio.

**Consecuencias.** Latencia de hasta 2 s entre lectura física y visibilidad. Aceptable para inventario; **no** aceptable para el portal antihurto, que usa un canal prioritario aparte (ver documento 07, §6).

---

### ADR-005 — Modelo de stock derivado, con instantáneas

**Contexto.** ¿El stock es una columna que se incrementa, o se calcula desde los movimientos?

**Decisión.** **Event sourcing ligero**: la fuente de verdad es la tabla `stock_movements` (append-only). El stock actual vive en `tag_states` (una fila por tag, actualizada transaccionalmente) y en `stock_snapshots` (agregado por SKU/ubicación, materializado).

**Justificación.** Con RFID, cada unidad es rastreable. Recalcular desde movimientos es auditable y permite reconstruir el estado en cualquier fecha, que es exactamente lo que exige una investigación de merma.

**Consecuencias.** Más complejidad de escritura. Se mitiga con un único servicio de dominio (`StockMovementService`) que es el **único** punto autorizado para mutar stock.

---

### ADR-006 — Multi-tienda desde el día uno, multi-tenant no

**Decisión.** El modelo soporta N tiendas/ubicaciones dentro de **una** organización. No se implementa aislamiento multi-tenant (una base por cliente, o `tenant_id` en todo).

**Justificación.** El caso de uso es un retailer con varios locales, no un SaaS. Añadir tenancy ahora cuesta y no se usa.

**Consecuencias.** Si el producto se comercializa como SaaS más adelante, requiere refactor. Se mitiga: toda tabla de negocio lleva `organization_id` desde el inicio, aunque hoy siempre valga `1`.

---

### ADR-007 — Tiempo real por WebSocket con Laravel Reverb

**Decisión.** Los eventos de inventario en curso, alertas de portal y progreso de ciclo se emiten por **Laravel Reverb** (WebSocket propio, auto-hospedado).

**Justificación.** Coherente con la preferencia del equipo por infraestructura auto-hospedada; evita el coste y la dependencia de Pusher.

---

### ADR-008 — La app de mano es nativa Android, no híbrida

**Decisión.** **Kotlin nativo**, no React Native ni Flutter.

**Justificación.** Los SDK de los lectores (Zebra EMDK/RFID API3, Chainway, Honeywell) son librerías Android nativas. Envolverlas en un puente añade una capa frágil justo en la parte más crítica del sistema. El coste de mantener una app nativa pequeña y muy enfocada es menor que el de depurar un puente RFID.

**Consecuencias.** No hay app iOS. No se necesita: los handhelds industriales son Android.

---

## 5. Topología de red por tienda

```
   Internet
      │
  ┌───▼────┐
  │ Router │  (IP fija o DDNS; VPN WireGuard hacia el central)
  └───┬────┘
      │  LAN 192.168.10.0/24
      ├──────────────┬─────────────┬────────────────┬──────────────┐
      │              │             │                │              │
 ┌────▼────┐   ┌─────▼─────┐  ┌────▼─────┐   ┌──────▼─────┐  ┌─────▼──────┐
 │ traza-  │   │ Lector    │  │ Lector   │   │ Impresora  │  │ AP Wi-Fi   │
 │ edge    │   │ portal    │  │ túnel    │   │ RFID       │  │ 5 GHz      │
 │ .10     │   │ .21       │  │ .22      │   │ .31        │  │ .5         │
 └─────────┘   └───────────┘  └──────────┘   └────────────┘  └──────┬─────┘
                                                                     │
                                                            ┌────────▼───────┐
                                                            │ Handhelds      │
                                                            │ DHCP .100-.150 │
                                                            └────────────────┘
```

### Reglas de red

| Regla | Motivo |
|---|---|
| Lectores e impresora con **IP fija** | LLRP y ZPL se dirigen por IP; DHCP rotativo rompe la configuración |
| VLAN separada para dispositivos RFID | Evita que el tráfico de invitados afecte a LLRP |
| Wi-Fi de handhelds en **5 GHz** | 2.4 GHz no interfiere con 915 MHz, pero está saturado en galerías comerciales |
| VPN WireGuard tienda ↔ central | El middleware necesita canal seguro; evita exponer la API a internet abierta |
| `traza-edge` con IP fija y watchdog | Es el único punto de fallo local; debe reiniciarse solo |

> **Nota sobre 2.4 GHz vs 915 MHz**: no comparten banda, así que no hay interferencia directa. Pero un lector emitiendo 1 W genera armónicos y ruido de banda ancha que sí puede degradar Wi-Fi cercano. Separar físicamente el AP del portal al menos 2 m.

---

## 6. Módulos funcionales del backend

| Módulo | Responsabilidad | Agregado raíz |
|---|---|---|
| `Catalog` | Productos, variantes (SKU), categorías, temporadas, proveedores | `Product` |
| `Tagging` | Tags EPC, tarado, asignación EPC↔SKU, ciclo de vida del tag | `Tag` |
| `Inventory` | Ciclos de conteo, reconciliación, ajustes | `InventoryCycle` |
| `Movements` | Movimientos de stock, transferencias, historial | `StockMovement` |
| `Receiving` | Órdenes de compra, ASN, recepción por túnel | `ReceivingOrder` |
| `Sales` | Descarga de stock por venta, devoluciones | `SaleTransaction` |
| `Loss` | Detección de merma, alertas de portal, investigación | `LossEvent` |
| `Devices` | Registro de lectores, antenas, perfiles de lectura, salud | `Device` |
| `Analytics` | Vistas materializadas, KPI, exportaciones | — |
| `Identity` | Usuarios, roles, permisos, auditoría | `User` |

### Diagrama de dependencias entre módulos

```
        Identity ◀────────── (todos)
           ▲
           │
  Catalog ─┴──▶ Tagging ──▶ Movements ◀── Receiving
                   │            ▲            │
                   │            │            │
                   └──▶ Inventory            │
                            │                │
                            └──▶ Loss ◀──────┘
                                  ▲
                               Sales
                                  
        Devices ──▶ (Tagging, Inventory, Loss)   [solo lectura de configuración]
        Analytics ──▶ (todos)                    [solo lectura]
```

**Restricción**: `Movements` no depende de nadie salvo `Catalog` y `Tagging`. Es el corazón transaccional y debe permanecer estable.

---

## 7. Flujo de datos principal

```
 ┌─────────┐  1. lectura cruda    ┌─────────────┐  2. lote filtrado
 │ Lector  │─────────────────────▶│ traza-edge  │────────────────────┐
 └─────────┘  (LLRP / SDK)        └─────────────┘  (HTTPS POST)      │
                                          │                           │
                                          │ 2b. eventos urgentes      │
                                          │     (portal)              │
                                          ▼                           ▼
                                    ┌──────────┐            ┌───────────────────┐
                                    │  MQTT    │            │ POST /api/v1/     │
                                    │  broker  │            │  ingest/reads     │
                                    └────┬─────┘            └─────────┬─────────┘
                                         │                            │
                                         │                  3. INSERT masivo
                                         │                            ▼
                                         │                  ┌───────────────────┐
                                         │                  │  tag_reads        │
                                         │                  │  (particionada)   │
                                         │                  └─────────┬─────────┘
                                         │                            │
                                         │                  4. encola job
                                         ▼                            ▼
                                 ┌───────────────┐          ┌───────────────────┐
                                 │ Listener MQTT │          │ ProcessReadBatch  │
                                 │ (alerta EAS)  │          │ (Horizon)         │
                                 └───────┬───────┘          └─────────┬─────────┘
                                         │                            │
                                         │                  5. reglas de dominio
                                         │                            ▼
                                         │                  ┌───────────────────┐
                                         │                  │ tag_states        │
                                         │                  │ stock_movements   │
                                         │                  └─────────┬─────────┘
                                         │                            │
                                         └────────────┬───────────────┘
                                                      ▼
                                            ┌───────────────────┐
                                            │ Broadcast Reverb  │
                                            │ → React en vivo   │
                                            └───────────────────┘
```

---

## 8. Máquina de estados del tag

Este es el corazón conceptual del sistema. Todo tag existe en exactamente uno de estos estados.

```
                    ┌──────────────┐
                    │   CREADO     │  EPC generado, aún no impreso
                    └──────┬───────┘
                           │ imprimir + codificar
                           ▼
                    ┌──────────────┐
                    │  CODIFICADO  │  Físicamente escrito en un inlay
                    └──────┬───────┘
                           │ asociar a SKU + unidad física
                           ▼
                    ┌──────────────┐
              ┌────▶│   EN_STOCK   │◀──────────┐
              │     └──┬────┬───┬──┘           │
              │        │    │   │              │ devolución
   retorno de │        │    │   │ venta        │
   transfer.  │        │    │   └──────────────┼────┐
              │        │    │                  │    ▼
     ┌────────┴──┐     │    │              ┌───┴────────┐
     │ EN_TRANSITO│◀───┘    │              │  VENDIDO   │
     └───────────┘  transf. │              └────────────┘
                            │
              ┌─────────────┼──────────────┬──────────────┐
              ▼             ▼              ▼              ▼
       ┌───────────┐  ┌──────────┐  ┌───────────┐  ┌────────────┐
       │ NO_VISTO  │  │ PERDIDO  │  │  DAÑADO   │  │  BAJA      │
       │ (n ciclos)│  │ (merma)  │  │           │  │ (devolución│
       └─────┬─────┘  └──────────┘  └───────────┘  │ a proveedor)│
             │ reaparece                            └────────────┘
             └──────────▶ EN_STOCK
```

| Estado | Descripción | Transiciones válidas hacia |
|---|---|---|
| `creado` | EPC reservado en base, sin soporte físico | `codificado`, `anulado` |
| `codificado` | Escrito en un inlay, sin prenda asignada | `en_stock`, `anulado` |
| `en_stock` | Asociado a prenda, presente en una ubicación | `vendido`, `en_transito`, `no_visto`, `dañado`, `baja` |
| `en_transito` | Enviado entre ubicaciones, no recibido | `en_stock`, `perdido` |
| `no_visto` | No detectado en N ciclos consecutivos | `en_stock` (reaparece), `perdido` |
| `perdido` | Declarado merma tras M ciclos | `en_stock` (reaparición tardía, genera alerta) |
| `vendido` | Salió por caja | `en_stock` (devolución) |
| `dañado` | Prenda inservible | `baja` |
| `baja` | Fuera del inventario definitivamente | — (terminal) |
| `anulado` | EPC descartado antes de usarse | — (terminal) |

> **Regla dura**: toda transición de estado genera una fila en `stock_movements` con usuario, dispositivo, ubicación y motivo. No hay excepciones. Un cambio de estado sin movimiento es un bug.

---

## 9. Requisitos no funcionales

| Categoría | Requisito | Medida |
|---|---|---|
| **Rendimiento** | Ingesta de lecturas | ≥ 5 000 lecturas/s por instancia de API |
| | Latencia de alerta de portal | < 800 ms desde lectura física a alarma sonora |
| | Consulta de stock por SKU/tienda | p95 < 200 ms |
| | Cierre de ciclo de inventario de 20 000 prendas | < 60 s |
| **Disponibilidad** | Operación de tienda con internet caído | ≥ 8 h de captura en buffer local |
| | Disponibilidad del central | 99.5 % mensual |
| **Escalabilidad** | Tiendas | Hasta 50 sin cambio arquitectónico |
| | Prendas activas | Hasta 2 M de tags en estado no terminal |
| **Retención** | Lecturas crudas | 90 días en caliente, 24 meses en frío (Parquet en MinIO) |
| | Movimientos de stock | 10 años (requisito contable/tributario) |
| **Seguridad** | Autenticación | Sanctum + tokens de dispositivo rotables |
| | Transporte | TLS 1.3 obligatorio; MQTT sobre TLS |
| **Usabilidad** | Curva de aprendizaje del handheld | Operario productivo en < 30 min de formación |
| | Idioma | Español (es-PE) como base; arquitectura i18n para inglés |
| **Observabilidad** | Métricas de salud de lector | Latido cada 30 s; alerta a los 2 min sin latido |

---

## 10. Estrategia de despliegue por entornos

| Entorno | Ubicación | Propósito | Datos |
|---|---|---|---|
| `local` | OrbStack en Mac del desarrollador | Desarrollo | Semilla sintética + **simulador de lector** |
| `staging` | Mac mini M4 del equipo | Integración, pruebas de hardware real | Copia anonimizada |
| `production` | VPS + edge en cada tienda | Operación | Real |

> El **simulador de lector** (documento 07, §8) es un entregable de primera clase. Sin él, ningún desarrollador puede avanzar sin tener un lector de USD 2 500 en el escritorio.
