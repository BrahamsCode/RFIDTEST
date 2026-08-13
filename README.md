# TRAZA — Sistema de Control de Stock por RFID

**Documentación técnica y funcional completa del ecosistema**

| Campo | Valor |
|---|---|
| Nombre clave | TRAZA |
| Versión del documento | 1.0 |
| Fecha | Agosto 2026 |
| Autor | VivaTech |
| Stack objetivo | Laravel 11 · React 18 + Vite · PostgreSQL 16 · Docker (OrbStack) |
| Dominio | Retail de indumentaria — inventario, antihurto y punto de venta |
| Alcance geográfico | Perú (Lima / Gamarra) con arquitectura multi-tienda |

---

## 1. Resumen ejecutivo

TRAZA es una plataforma de **identificación por radiofrecuencia (RFID UHF pasivo, EPC Gen2)** para control de inventario en retail de ropa. Reemplaza el conteo manual y el escaneo por código de barras (uno a uno, con línea de vista) por lectura masiva sin línea de vista: **cientos de prendas por segundo**.

### El problema que resuelve

En una tienda de ropa promedio sin RFID:

- La **exactitud de inventario** (*inventory accuracy*) se sitúa típicamente entre 60 % y 75 %. Es decir, uno de cada tres registros del sistema no coincide con la realidad física.
- El **inventario físico completo** toma de 8 a 40 horas-persona y se hace 1 a 4 veces al año, con la tienda cerrada.
- La **merma desconocida** (hurto interno, externo, error administrativo) no se detecta hasta el conteo anual.
- Las **rupturas de stock en sala** (producto existe en almacén pero no en góndola) provocan venta perdida invisible.

Con RFID correctamente implantado:

- Exactitud de inventario sostenida en **97–99 %**.
- Inventario completo de tienda en **20–45 minutos por persona**, semanal o quincenal.
- Detección de merma con granularidad de SKU y ventana temporal corta.
- Reposición de sala guiada por diferencia entre *stock en piso* y *stock en trastienda*.

### Lo que NO resuelve por sí solo

Es importante fijar expectativas desde el documento fundacional:

- RFID **no elimina el hurto**; lo detecta y lo cuantifica. La disuasión depende del portal EAS y del protocolo humano.
- RFID **no corrige procesos rotos**. Si la recepción de mercadería es caótica, RFID solo hace visible el caos más rápido.
- Los tags **no leen bien a través de metal ni líquido**. En ropa esto rara vez es problema, salvo en accesorios metálicos, hebillas, jeans con muchos remaches apilados, y perchas metálicas densas.
- La lectura masiva **sobre-lee**: el lector capta tags de la trastienda, del probador vecino o de la tienda contigua. Controlar esto es un problema de ingeniería de RF, no de software, y consume una parte sustancial del esfuerzo de puesta en marcha.

---

## 2. Índice de la documentación

| # | Documento | Contenido |
|---|---|---|
| 00 | `README.md` (este archivo) | Resumen ejecutivo, índice, glosario, convenciones |
| 01 | `docs/01-fundamentos-rfid.md` | Física de RF, EPC Gen2, protocolo de aire, regulación peruana (MTC/PNAF), tipos de tag, anticolisión |
| 02 | `docs/02-arquitectura.md` | Arquitectura de capas, diagramas C4, decisiones arquitectónicas (ADR), topología de red |
| 03 | `docs/03-hardware-y-bom.md` | Catálogo de equipos, lista de materiales, criterios de selección, site survey, layout físico de tienda |
| 04 | `docs/04-codificacion-epc.md` | Esquema SGTIN-96, política de numeración, GS1 Perú, codificación en impresora, memoria del tag |
| 05 | `docs/05-modelo-de-datos.md` | Modelo entidad-relación, diccionario de datos, estrategia de particionado, retención |
| 06 | `docs/06-backend-laravel.md` | Estructura de módulos, servicios de dominio, API REST, eventos, jobs, migraciones |
| 07 | `docs/07-middleware-rfid.md` | Servicio de captura, LLRP, MQTT, deduplicación, filtro de lecturas fantasma, buffer offline |
| 08 | `docs/08-frontend-react.md` | Aplicación web, pantallas, componentes, estado, tiempo real |
| 09 | `docs/09-app-handheld.md` | Aplicación Android para lector de mano, SDK, modos de operación, UX de inventario |
| 10 | `docs/10-procesos-operativos.md` | Los 12 procesos operativos documentados paso a paso con diagramas de flujo |
| 11 | `docs/11-infraestructura-docker.md` | Compose, entornos, CI/CD, observabilidad, backups, edge computing |
| 12 | `docs/12-seguridad-y-privacidad.md` | Amenazas RFID, clonación, kill password, protección de datos personales, Ley 29733 |
| 13 | `docs/13-kpis-y-analitica.md` | Indicadores, vistas materializadas, tableros, alertas |
| 14 | `docs/14-roadmap-costos-riesgos.md` | Plan por fases, presupuesto en USD/PEN, análisis de retorno, matriz de riesgos |
| 15 | `docs/15-plan-de-pruebas.md` | Estrategia de QA, pruebas de RF, criterios de aceptación, pruebas de campo |
| 16 | `docs/16-documento-de-seguridad.md` | Estado real de la lista de verificación previa a producción |
| — | `sql/schema.sql` | DDL completo de PostgreSQL |
| — | `sql/seeds.sql` | Datos de referencia y semilla |
| — | `sql/vistas-analiticas.sql` | Vistas materializadas y funciones de reporte |
| — | `infra/docker-compose.yml` | Orquestación de desarrollo |
| — | `infra/docker-compose.prod.yml` | Orquestación de producción |
| — | `infra/.env.example` | Variables de entorno |
| — | `tasks/TASKS.md` | Backlog ejecutable por agentes (Claude Code) |

---

## 3. Glosario

| Término | Definición |
|---|---|
| **RFID** | Radio Frequency Identification. Identificación de objetos mediante ondas de radio. |
| **UHF** | Ultra High Frequency. Banda 300 MHz – 3 GHz. En RFID retail: 860–960 MHz. |
| **Tag pasivo** | Etiqueta sin batería. Se alimenta de la energía RF emitida por el lector. |
| **EPC** | Electronic Product Code. Identificador único global almacenado en el tag. |
| **Gen2** | EPCglobal UHF Class 1 Generation 2. Protocolo de aire estándar. Equivale a ISO/IEC 18000-63. |
| **TID** | Tag Identifier. Memoria de solo lectura grabada en fábrica; identifica el chip de forma única e inalterable. |
| **SGTIN** | Serialized Global Trade Item Number. GTIN + número de serie. El esquema EPC usado en retail. |
| **GTIN** | Global Trade Item Number. El código de producto GS1 (el que hay detrás del código de barras EAN-13). |
| **Inlay** | El conjunto chip + antena impresa, antes de laminarse en una etiqueta. |
| **LLRP** | Low Level Reader Protocol. Estándar de comunicación entre lector fijo y software. |
| **DRM / Dense Reader Mode** | Modo de operación que permite varios lectores próximos sin interferirse. |
| **RSSI** | Received Signal Strength Indicator. Potencia de la señal recibida del tag. Se usa para inferir distancia. |
| **ERP** | Effective Radiated Power. Potencia radiada, sujeta a límite regulatorio. |
| **Anticolisión** | Algoritmo (Slotted ALOHA en Gen2) que permite leer muchos tags sin que se solapen sus respuestas. |
| **Ciclo de inventario** | Conteo periódico total o parcial del stock físico mediante lector de mano. |
| **Merma / shrinkage** | Diferencia negativa entre stock teórico y stock físico. |
| **EAS** | Electronic Article Surveillance. Sistema antihurto en salida de tienda. |
| **Lectura fantasma** | Detección de un tag que no está realmente en la zona de interés (viene de una sala contigua, un rebote, o un falso positivo). |
| **Sobre-lectura / stray read** | Caso concreto de lectura fantasma por exceso de alcance. |
| **Handheld** | Lector RFID portátil tipo pistola o terminal móvil. |
| **Portal** | Arco con antenas fijas en una puerta, para detectar tránsito de tags. |
| **Túnel / cabina** | Recinto blindado con antenas para leer bultos completos en recepción. |
| **Tarado / commissioning** | Acto de asociar un EPC concreto a un SKU concreto en el sistema. |
| **Kill password** | Contraseña de 32 bits que permite desactivar un tag de forma permanente e irreversible. |

---

## 4. Convenciones de este documento

- **Idioma del código**: identificadores en inglés (`stock_movements`, `TagRepository`), comentarios y documentación en español.
- **Nombres de tabla**: `snake_case` plural.
- **Moneda**: los importes se expresan en USD para hardware importado y en PEN (S/) para servicios locales, con tipo de cambio referencial indicado en el documento 14.
- **Bloques marcados `⚠️ VERIFICAR`**: contienen datos que dependen de normativa, precios o disponibilidad y deben confirmarse antes de comprometer presupuesto.
- **ADR**: las decisiones arquitectónicas se numeran `ADR-001`, `ADR-002`… en el documento 02.
- Los ejemplos de código son ilustrativos y están pensados para ser el punto de partida de la implementación, no copia-pega de producción.

---

## 5. Cómo leer esto según tu rol

| Rol | Ruta de lectura sugerida |
|---|---|
| Dueño / decisor de negocio | 00 → 14 → 10 → 13 |
| Jefe de proyecto | 00 → 02 → 14 → 15 → `tasks/TASKS.md` |
| Arquitecto / tech lead | 01 → 02 → 04 → 05 → 07 → 11 |
| Backend | 05 → 06 → 07 → `sql/schema.sql` |
| Frontend | 08 → 09 → 06 (sección API) |
| Infraestructura | 11 → 12 → 03 |
| Operaciones de tienda | 10 → 09 |
| Agente de IA (Claude Code) | `tasks/TASKS.md` → documento referenciado en cada tarea |

---

## 6. Estructura del repositorio

```
README.md              Este documento
docs/                  Los 15 documentos técnicos
sql/                   DDL, semilla y vistas analíticas
infra/                 Compose de desarrollo y producción, Mosquitto
tasks/TASKS.md         Backlog ejecutable
traza-api/             Backend Laravel 11
traza-web/             Aplicación web Vite + React 18
traza-edge/            Middleware RFID de borde (Node + TypeScript)
traza-handheld/        Aplicación Android (Kotlin)
```

Cada proyecto tiene su propio `README.md` con puesta en marcha y estado de
implementación tarea por tarea.

### Arranque del entorno completo

```bash
cp infra/.env.example infra/.env      # rellenar antes de arrancar
docker compose -f infra/docker-compose.yml up -d
```

Sin lector físico no hace falta nada más: `traza-edge` arranca en modo
simulador por defecto.

---

## 7. Estado del proyecto

Este documento describe el sistema **objetivo**. La implementación está en la
Fase 0: existen los cuatro proyectos con sus cimientos y el simulador de
lector, pero el modelo de datos aún no está migrado a Laravel ni hay ingesta.
Ver `tasks/TASKS.md` para el detalle. El documento 14 define las fases; la Fase 0 (piloto de una tienda, un handheld, sin portal) es el mínimo viable para validar los supuestos de RF antes de comprometer capital en hardware fijo.

> **Recomendación fuerte**: no comprar portales, túneles ni impresoras RFID hasta haber completado la Fase 0 y medido tasas de lectura reales sobre tu propio surtido de prendas. Los números de la industria se calculan sobre catálogos que probablemente no se parecen al tuyo.
