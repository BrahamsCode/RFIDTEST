# 14 — Hoja de ruta, costos y riesgos

> ⚠️ Todas las cifras son estimaciones para planificación. Tipo de cambio referencial usado: **1 USD ≈ 3.75 PEN**. Los precios de hardware no incluyen IGV (18 %), aranceles, flete ni homologación. **Cotizar antes de comprometer presupuesto.**

---

## 1. Principio rector del plan

> **No construyas el sistema completo antes de saber si la radiofrecuencia funciona sobre tu ropa.**

El 80 % del riesgo del proyecto está concentrado en una pregunta que se responde en dos semanas y con USD 2 400: *¿leo el 98 % de mis prendas en una pasada?* Todo el plan está organizado alrededor de responderla primero.

---

## 2. Fases

### Fase 0 — Validación (3–4 semanas)

**Objetivo**: responder si RFID funciona sobre el surtido real, y si el equipo puede operarlo.

| Entregable | Detalle |
|---|---|
| Prueba de tasa de lectura | Protocolo del documento 03, §3.2, con 3 modelos de inlay sobre 100 prendas del peor caso |
| Site survey | Documento 03, §3.1 |
| Línea base de exactitud | **Inventario manual completo** de la tienda piloto. Sin esto no hay con qué comparar después |
| Codec EPC implementado y probado | Test del documento 04 pasando en CI |
| Esquema de base de datos desplegado | `sql/schema.sql` en el entorno local |
| Simulador de lector funcionando | Documento 07, §8 |
| App de mano mínima | Solo modo tarado y modo inventario, sin pulir |
| Tarado de 1 000 prendas | Prueba real de la operación más repetitiva |
| **Decisión documentada** | Continuar / ajustar / detener, con los datos de la prueba de lectura |

Presupuesto: **≈ USD 2 400** en hardware (documento 03, §2) + esfuerzo de desarrollo.

**Criterio de salida**: tasa de lectura ≥ 95 % en una pasada sobre el surtido real, y ≥ 99 % en tres pasadas. Si no se alcanza con ningún inlay probado, **detener y replantear**, no aumentar el presupuesto.

---

### Fase 1 — Tienda piloto operativa (10–14 semanas)

**Objetivo**: una tienda funcionando de verdad, con ciclos semanales.

| Bloque | Contenido |
|---|---|
| Backend | Catálogo, tags, movimientos, ciclos, ingesta, reconciliación, alertas |
| Web | Panel, ciclos en vivo, stock, ficha de prenda, reposición, catálogo, etiquetas |
| Handheld | Los 5 modos, sincronización offline, alta por QR |
| Borde | Pipeline completo, buffer, adaptador del lector elegido |
| Hardware | 2 handhelds, impresora, portal de salida, túnel de recepción |
| Operación | Tarado del inventario completo de la tienda, formación del equipo |
| Infraestructura | Compose de producción, respaldos verificados, observabilidad |

Presupuesto de hardware: **≈ USD 19 700** (documento 03, §2).

**Criterio de salida**: 4 ciclos semanales consecutivos con exactitud ≥ 95 % y sin intervención del equipo técnico.

---

### Fase 2 — Réplica y madurez (8–12 semanas)

| Bloque | Contenido |
|---|---|
| Multi-tienda | Transferencias entre locales, supervisión regional, consolidado |
| Portal de trastienda | Separación automática sala / almacén |
| Integración con POS | Descarga de stock por venta en tiempo real |
| Analítica | Tableros de gerencia, exportación a frío, informes de merma |
| Endurecimiento | Auditoría completa, doble aprobación, recuperación ante desastres probada |

Presupuesto: **≈ USD 11 500 por tienda adicional**.

---

### Fase 3 — Cadena de suministro (12+ semanas)

| Bloque | Contenido |
|---|---|
| **Source tagging** | El proveedor etiqueta en su planta con rangos EPC delegados |
| ASN | Aviso anticipado de despacho: sabes qué viene antes de que llegue |
| Migración a SGTIN estándar | Si se arrancó con GID-96 |
| Integración con ERP | Publicación de movimientos valorizados |
| Autoservicio en caja | El cliente pasa las prendas por una antena y paga solo |

> El *source tagging* es lo que convierte el proyecto de "un sistema de inventario" a "una ventaja operativa". Elimina el tarado, que es el 70 % del coste operativo recurrente.

---

## 3. Cronograma

```
        Sem  1  2  3  4 │ 5  6  7  8  9 10 11 12 13 14 15 16 17 18 │ …
             ─────────────────────────────────────────────────────────
FASE 0       ████████████
  Pruebas RF ████████
  Codec+BD      ██████
  Simulador       ████
  App mínima        ██████
  Decisión              ▲

FASE 1                  │ ████████████████████████████████████████
  Backend               │ ████████████████████
  Web                   │      ████████████████████
  Handheld              │   ████████████████████
  Borde                 │        ████████████
  Hardware (compra)     │ ████████            ← plazo de importación: 4–8 semanas
  Instalación           │                ████████
  Tarado masivo         │                      ████████
  Formación             │                            ████
  Piloto en vivo        │                              ████████████
                        │                                       ▲ salida

FASE 2                  │                                       │ ████████…
```

> **El plazo de importación del hardware es el camino crítico y casi siempre se subestima.** Un lector pedido desde Asia puede tardar entre 4 y 10 semanas incluyendo aduana y homologación. **Encargar el hardware de Fase 1 en cuanto la Fase 0 dé positivo**, no cuando el software esté listo.

---

## 4. Estimación de esfuerzo

| Componente | Persona-semanas |
|---|---|
| Backend Laravel (dominio, API, ingesta, reconciliación) | 12–16 |
| Middleware `traza-edge` | 6–9 |
| Web React | 8–12 |
| App Android | 6–9 |
| Infraestructura, CI/CD, observabilidad | 3–5 |
| Pruebas, calibración de RF, ajuste en campo | 4–6 |
| Documentación, formación, gestión | 3–4 |
| **Total Fases 0 + 1** | **42–61 persona-semanas** |

Con un equipo de 3 personas a tiempo completo: **14–20 semanas**. Con 2 personas: 21–30 semanas.

> La partida de **"calibración de RF y ajuste en campo"** es la que más se subestima. No es depuración de software: es mover antenas, cambiar potencias, repetir mediciones y ajustar umbrales. Requiere presencia física en la tienda y no se puede paralelizar.

---

## 5. Análisis de retorno

### Supuestos del escenario base

| Variable | Valor |
|---|---|
| Tiendas | 1 (Fase 1) |
| Unidades en stock | 20 000 |
| Ventas anuales | S/ 1 800 000 |
| Merma actual estimada | 2.5 % de ventas = **S/ 45 000/año** |
| Rotación anual de unidades | 60 000 prendas etiquetadas/año |
| Inventarios manuales actuales | 4/año × 40 h × 3 personas |
| Coste hora del personal | S/ 12 |

### Costos

| Concepto | Año 1 (S/) | Año 2+ (S/) |
|---|---:|---:|
| Hardware (Fase 0 + 1) | 82 900 | — |
| Tags: 60 000 × USD 0.08 | 18 000 | 18 000 |
| Desarrollo (interno, coste de oportunidad) | 120 000 | 20 000 |
| Infraestructura (VPS, dominios, respaldos) | 3 000 | 3 000 |
| Mantenimiento de hardware, repuestos | 2 000 | 4 000 |
| Operación adicional (tarado: ≈5 s/prenda) | 12 500 | 12 500 |
| **Total** | **238 400** | **57 500** |

### Beneficios

| Concepto | Anual (S/) | Cómo se calcula |
|---|---:|---|
| Reducción de merma | 27 000 | De 2.5 % a 1.0 % de ventas |
| Ahorro en inventarios manuales | 5 760 | 480 h × S/ 12 |
| Venta recuperada por disponibilidad | 36 000 | +2 % de ventas por mejor reposición |
| Reducción de venta perdida por localización | 9 000 | 0.5 % de ventas |
| Menos horas de búsqueda de prendas | 4 800 | 400 h × S/ 12 |
| **Total** | **82 560** | |

### Resultado

| | Año 1 | Año 2 | Año 3 |
|---|---:|---:|---:|
| Beneficio | 82 560 | 82 560 | 82 560 |
| Coste | 238 400 | 57 500 | 57 500 |
| Flujo | −155 840 | +25 060 | +25 060 |
| Acumulado | −155 840 | −130 780 | −105 720 |

**Con una sola tienda, el proyecto no se paga.** Es la conclusión honesta y hay que decirla.

### Escenario de 5 tiendas

El desarrollo se amortiza entre todas; el hardware por tienda es marginal.

| | Año 1 | Año 2 | Año 3 |
|---|---:|---:|---:|
| Beneficio (5 × 82 560) | 412 800 | 412 800 | 412 800 |
| Coste | 460 000 | 190 000 | 190 000 |
| Flujo | −47 200 | +222 800 | +222 800 |
| Acumulado | −47 200 | +175 600 | +398 400 |

**Punto de equilibrio: mes 14 aproximadamente.**

> **Conclusión estratégica**: TRAZA tiene sentido económico a partir de **3–4 tiendas**, o para una sola tienda con volumen y merma altos. Para un solo local pequeño, el retorno es dudoso salvo que el desarrollo se aproveche para otros fines (producto vendible a terceros, aprendizaje del equipo).
>
> Esta es exactamente la conversación que hay que tener **antes** de la Fase 1, no después.

---

## 6. Matriz de riesgos

| # | Riesgo | Prob. | Impacto | Exposición | Mitigación | Dueño |
|---|---|---|---|---|---|---|
| R1 | La tasa de lectura sobre el surtido real es insuficiente | Media | **Crítico** | Alta | Fase 0 antes de cualquier compra grande. Probar 3 inlays | Técnico |
| R2 | Hardware retenido en aduana o sin homologar | **Alta** | Alto | **Alta** | Comprar a distribuidor con certificado MTC. Presupuestar 8 semanas | Compras |
| R3 | El equipo de tienda no adopta el proceso (no barren zonas) | **Alta** | Alto | **Alta** | UX simplísima, formación en vídeo, aviso de zona lenta en vivo, indicador por operario | Jefe de tienda |
| R4 | Sobre-lectura del local vecino en Gamarra | Media | Medio | Media | Máscara EPC, potencia baja, conteo por zonas, umbral de RSSI | Técnico |
| R5 | El coste del tag anula el margen en prendas baratas | Media | Alto | Media | Etiquetar solo por encima de un precio umbral. RFID no tiene por qué ser 100 % del surtido |  Gerencia |
| R6 | El proyecto se alarga y pierde patrocinio | Media | Alto | Media | Fases cortas con entregable visible. La Fase 0 dura 3 semanas a propósito | Gestión |
| R7 | Falsos positivos del portal llevan a desconectarlo | Media | Medio | Media | Umbral de confianza, registro obligatorio de falsos positivos, recalibración trimestral | Técnico |
| R8 | Tags arrancados degradan la exactitud | Media | Medio | Media | Vigilar la tasa de sustitución; cambiar posición o tipo de etiqueta | Almacén |
| R9 | Se declara merma falsa y el equipo pierde confianza | Media | **Crítico** | Alta | Umbral de 2 ciclos, protocolo de búsqueda antes de declarar, reversión sencilla | Jefe de tienda |
| R10 | Dependencia de una sola persona que entiende el sistema | **Alta** | Alto | **Alta** | Esta documentación. Rotación de responsable técnico. Manual de incidencias (doc. 11, §9) | Gestión |
| R11 | Corte prolongado de internet o electricidad | Media | Medio | Media | Arquitectura offline-first, UPS, buffer de 8 h | Técnico |
| R12 | Cambio regulatorio del MTC en la banda | Baja | Alto | Baja | Perfil regional configurable por dispositivo; seguimiento del PNAF | Técnico |
| R13 | Sanción por incumplimiento de la Ley 29733 | Baja | Alto | Media | Documento 12, §4. Asesoría legal antes de producción | Gerencia |
| R14 | Bug en el codec EPC corrompe identificadores | Baja | **Crítico** | Media | Test bloqueante en CI, verificación bidireccional, prefijo de pruebas separado | Backend |
| R15 | Pérdida de la clave maestra de access password | Baja | Alto | Media | Custodia fuera de línea en dos ubicaciones selladas | Admin |

### Los tres riesgos que realmente matan el proyecto

1. **R3 — Adopción.** El sistema técnicamente perfecto que nadie usa correctamente produce datos peores que el conteo manual, porque además genera falsa confianza. La respuesta no es más software: es una interfaz de handheld que se aprende en 20 minutos y un proceso que cabe en una página.

2. **R9 — Pérdida de confianza por merma falsa.** Basta con que el sistema declare perdidas tres prendas que después aparecen para que el jefe de tienda deje de creer en cualquier número que salga de él. De ahí el umbral de 2 ciclos y el protocolo de búsqueda obligatorio.

3. **R1 — Física.** Es el único riesgo que no se puede resolver con esfuerzo. Por eso se prueba primero, barato, y con criterio de parada escrito de antemano.

---

## 7. Decisiones pendientes antes de arrancar

| # | Decisión | Quién decide | Cuándo | Impacto si se retrasa |
|---|---|---|---|---|
| D1 | ¿Se afilia la empresa a GS1 Perú? | Gerencia | Antes de Fase 0 | Determina el esquema EPC. Cambiarlo después obliga a re-etiquetar |
| D2 | ¿Qué modelo de handheld? | Técnico + Compras | Semana 1 de Fase 0 | Camino crítico de importación |
| D3 | ¿Se etiqueta el 100 % del surtido o solo por encima de un precio? | Gerencia | Antes de Fase 1 | Cambia el cálculo de retorno por completo |
| D4 | ¿Hangtag propio o pre-codificado? | Operaciones | Antes de Fase 1 | Determina si hace falta impresora (USD 3 400) |
| D5 | ¿Cuántas tiendas en el horizonte de 24 meses? | Gerencia | Antes de Fase 1 | Determina si el proyecto tiene retorno (§5) |
| D6 | ¿Portal antihurto en Fase 1 o Fase 2? | Gerencia | Antes de comprar | USD 3 800 y varias semanas de calibración |
| D7 | ¿Servidor propio o VPS? | Técnico | Antes de Fase 1 | Afecta a la disponibilidad y al plan de recuperación |
| D8 | ¿Quién es el responsable técnico de guardia? | Gestión | Antes de producción | R10 |

---

## 8. Criterios de éxito del proyecto

Medidos a los 6 meses de la Fase 1 en producción:

| Criterio | Umbral |
|---|---|
| Exactitud de inventario sostenida | ≥ 97 % en 8 ciclos consecutivos |
| Duración del ciclo | ≤ 45 min para 20 000 unidades |
| Disponibilidad en sala | ≥ 95 % |
| Merma clasificada (no "desconocida") | ≥ 60 % de la merma con causa identificada |
| Ciclos completados sin intervención técnica | ≥ 95 % |
| Falsos positivos de portal | ≤ 15 % de las alarmas |
| Tiempo de tarado | ≤ 6 s por prenda en régimen |
| Satisfacción del equipo de tienda | El sistema se usa sin recordatorios |

> El último criterio no es medible con una consulta SQL y es el más importante. Si a los 6 meses hay que recordarle al equipo que haga el ciclo semanal, el proyecto ha fallado aunque todos los números técnicos estén en verde.
