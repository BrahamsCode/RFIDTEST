# 10 — Procesos operativos

> Este es el documento que leen las personas de tienda. Está escrito para ellas, no para el equipo técnico. Si un proceso no cabe en una página, está mal diseñado.

---

## Índice de procesos

| # | Proceso | Frecuencia | Quién | Duración típica |
|---|---|---|---|---|
| P01 | Tarado de mercadería nueva | Cada recepción | Almacén | 4–8 s por prenda |
| P02 | Recepción por túnel | Cada llegada | Almacén | 2–4 s por bulto |
| P03 | Ciclo de inventario | Semanal | Tienda | 25–45 min |
| P04 | Conciliación y ajuste | Tras cada ciclo | Jefe de tienda | 10–20 min |
| P05 | Reposición de sala | Diaria | Vendedor | 15 min |
| P06 | Venta con RFID | Continua | Caja | +0 s (integrado) |
| P07 | Devolución de cliente | Según ocurra | Caja | 30 s |
| P08 | Transferencia entre tiendas | Según ocurra | Almacén | 5 min por bulto |
| P09 | Alarma de portal | Según ocurra | Vendedor | 30 s |
| P10 | Búsqueda de prenda concreta | Bajo demanda | Vendedor | 1–3 min |
| P11 | Re-etiquetado por tag perdido | Según ocurra | Vendedor | 1 min |
| P12 | Devolución a proveedor | Según ocurra | Almacén | 5 min |

---

## P01 — Tarado de mercadería nueva

**Objetivo**: que cada prenda física tenga un EPC único asociado a su SKU.

```
  ┌──────────────────────────┐
  │ Llega mercadería nueva   │
  └────────────┬─────────────┘
               ▼
  ┌──────────────────────────┐
  │ ¿Viene ya etiquetada     │──── Sí ──▶ Ir a P02 (recepción por túnel)
  │ por el proveedor?        │
  └────────────┬─────────────┘
               │ No
               ▼
  ┌──────────────────────────┐
  │ Abrir el bulto y separar │
  │ por SKU                  │
  └────────────┬─────────────┘
               ▼
  ┌──────────────────────────┐
  │ Imprimir etiquetas del   │  (o coger un rollo de tags
  │ lote desde la web        │   pre-codificados)
  └────────────┬─────────────┘
               ▼
  ┌──────────────────────────┐
  │ Handheld → modo Tarado   │
  │ Fijar el SKU activo      │
  └────────────┬─────────────┘
               ▼
  ┌──────────────────────────┐◀─────────────────┐
  │ Colocar la etiqueta en   │                  │
  │ la prenda (mismo sitio   │                  │
  │ siempre)                 │                  │
  └────────────┬─────────────┘                  │
               ▼                                │
  ┌──────────────────────────┐                  │
  │ Apretar el gatillo       │                  │
  └────────────┬─────────────┘                  │
               ▼                                │
        ¿Pitido correcto?                       │
         ┌─────┴─────┐                          │
        Sí           No                         │
         │            │                         │
         │            ▼                         │
         │   ┌──────────────────┐               │
         │   │ Descartar tag y  │───────────────┘
         │   │ usar otro        │
         │   └──────────────────┘
         ▼
  ┌──────────────────────────┐
  │ Siguiente prenda         │──── quedan ──────┘
  └────────────┬─────────────┘
               │ no quedan
               ▼
  ┌──────────────────────────┐
  │ Cambiar SKU o cerrar     │
  └──────────────────────────┘
```

### Reglas del tarado

1. **La etiqueta va siempre en el mismo sitio** de la prenda para cada tipo de producto. Etiqueta de cintura en pantalones, etiqueta de cuello en camisetas. La consistencia es más valiosa que la posición perfecta.
2. **Potencia baja**. Si el handheld lee prendas de la caja de al lado, el tarado está mal configurado. Avisar al responsable.
3. **Etiqueta VOID = a la basura**. Nunca se pega una etiqueta marcada VOID.
4. **Un pitido grave largo significa conflicto**: ese tag ya pertenece a otro producto. No continuar; llamar al responsable.
5. **No tarar con la caja abierta al lado de mercadería ya tarada.** Separar físicamente lo pendiente de lo hecho.

---

## P02 — Recepción por túnel

**Objetivo**: verificar en segundos que lo que llegó coincide con lo que se pidió.

1. Abrir en la web la orden de recepción correspondiente.
2. Pasar el bulto por el túnel.
3. La pantalla muestra en tiempo real:

```
┌──────────────────────────────────────────────────────┐
│  OC-2026-118 · Proveedor Textiles Andinos            │
│                                                       │
│  Leídos: 148        Esperados: 150                    │
│                                                       │
│  SKU              Esperado   Leído   Dif              │
│  ─────────────────────────────────────                │
│  Polera M negra        40      40     —               │
│  Polera L negra        40      40     —               │
│  Polera M blanca       40      38     -2  ⚠           │
│  Jean 30 azul          30      30     —               │
│                                                       │
│  ⚠ 2 prendas menos de lo esperado en un SKU.          │
│    Vuelve a pasar el bulto antes de reclamar.         │
│                                                       │
│  [ Repetir lectura ]   [ Aceptar con diferencia ]     │
└──────────────────────────────────────────────────────┘
```

4. **Siempre repetir la lectura antes de reclamar al proveedor.** Una diferencia de 1–2 unidades es más probable que sea un fallo de lectura que un faltante real.
5. Si la diferencia persiste tras dos pasadas, aceptar con diferencia. El sistema registra el faltante y genera la evidencia para la reclamación.

> **Umbral de alerta**: si la diferencia supera el 3 % del bulto, el sistema exige confirmación de un supervisor antes de aceptar.

---

## P03 — Ciclo de inventario

**Objetivo**: saber qué hay realmente en la tienda. Es el proceso central del sistema.

### Preparación (5 min)

| Paso | Detalle |
|---|---|
| 1 | Comprobar batería del handheld > 60 % |
| 2 | Cerrar el probador o revisarlo primero: es el agujero clásico del inventario |
| 3 | Recoger prendas del suelo y de los mostradores |
| 4 | Crear el ciclo en la web o en el handheld: elegir tienda y alcance |

### Barrido (25–40 min para 20 000 prendas)

**Orden recomendado**: escaparate → sala → probadores → caja → trastienda.

Técnica de barrido:

```
  Estantería vista de frente:

  ═══════════════════   ← nivel 1
  ═══════════════════   ← nivel 2      El lector se mueve en zigzag,
  ═══════════════════   ← nivel 3      a 30–50 cm de la ropa,
  ═══════════════════   ← nivel 4      a paso de caminata lenta.

  ┌───────────────────┐
  │ →→→→→→→→→→→→→→→→→ │  nivel 1
  │ ←←←←←←←←←←←←←←←←← │  nivel 2
  │ →→→→→→→→→→→→→→→→→ │  nivel 3
  │ ←←←←←←←←←←←←←←←←← │  nivel 4
  └───────────────────┘
```

Reglas:

1. **Cambiar de zona en la aplicación al cambiar de zona física.** Sin esto, el sistema no sabe dónde está cada prenda y la alerta de reposición no funciona.
2. **Las pilas dobladas necesitan dos ángulos**: barrer desde arriba y desde el lado.
3. **No correr.** Ir más rápido no acelera el conteo; lo empeora, y hay que repetir.
4. **Mirar el contador de vez en cuando.** Si deja de subir en una zona con mucha ropa, algo va mal (batería, lector desconectado, potencia mal configurada).

### Segunda pasada

Al terminar, la aplicación muestra las zonas con menor porcentaje. **Volver a barrer las que estén por debajo del 90 %** antes de cerrar. Una segunda pasada de 5 minutos en la zona correcta ahorra una hora de investigación después.

---

## P04 — Conciliación y ajuste

Al cerrar el ciclo, el sistema clasifica todo en tres grupos:

| Grupo | Significado | Acción |
|---|---|---|
| **Encontradas** | Estaban y se contaron | Ninguna |
| **Faltantes** | Deberían estar y no aparecieron | Investigar antes de dar por perdida |
| **Inesperadas** | Aparecieron y no deberían estar | Investigar el origen |

### Protocolo para faltantes

```
  Prenda faltante
        │
        ▼
  ¿Es la primera vez que falta?
   ┌────┴────┐
  Sí         No (2º ciclo o más)
   │          │
   ▼          ▼
 Marcar    ┌──────────────────────────┐
 "no       │ Comprobar en este orden: │
 visto"    │ 1. Probadores            │
 y esperar │ 2. Mostrador de caja     │
 al        │ 3. Bolsas de reservas    │
 siguiente │ 4. Prendas en arreglo    │
 ciclo     │ 5. Devoluciones sin      │
           │    procesar              │
           └───────────┬──────────────┘
                       ▼
                ¿Apareció?
                 ┌──────┴──────┐
                Sí             No
                 │              │
                 ▼              ▼
          Escanear con    Declarar merma
          el handheld     con motivo
          (vuelve a       (el sistema
          "en stock")     registra quién
                          y cuándo)
```

> **Regla que evita destruir la confianza en el sistema**: una prenda **nunca** se declara perdida por un solo ciclo. El umbral por defecto son **dos ciclos consecutivos**. Si el sistema borra stock que después aparece, el equipo dejará de creerle, y ahí termina el proyecto.

### Protocolo para inesperadas

| Causa probable | Cómo se distingue | Acción |
|---|---|---|
| Prenda reaparecida | Estaba en `no_visto` o `perdido` | Ajuste positivo automático. Si estaba `perdido`, genera alerta para revisar el proceso |
| Transferencia no registrada | El tag pertenece a otra tienda | Registrar la transferencia retroactivamente |
| Devolución sin procesar | El tag está en estado `vendido` | Procesar la devolución en caja |
| Prenda de la tienda vecina | EPC desconocido | Revisar potencia del handheld; puede ser sobre-lectura |

---

## P05 — Reposición de sala

Uno de los procesos de mayor retorno y el que más rápido convence al equipo de tienda.

1. Abrir en la web `Stock → Reposición`.
2. La lista muestra: **hay en trastienda, no hay en sala**.

```
┌───────────────────────────────────────────────────────┐
│  Reposición pendiente · Tienda Gamarra 1              │
│                                                        │
│  Producto                Talla  En sala  En almacén    │
│  ──────────────────────────────────────────────────    │
│  Polera Oversize Negra     M        0         12   ▲   │
│  Polera Oversize Negra     L        0          8   ▲   │
│  Jean Slim Azul           32        1         14       │
│  Camisa Lino Beige         S        0          3       │
│                                                        │
│  ▲ = de los más vendidos este mes                      │
└───────────────────────────────────────────────────────┘
```

3. Bajar la mercadería.
4. **Al colocarla en sala, barrer con el handheld en modo inventario con la zona "Sala" activa.** Esto registra el cambio de zona y la lista se actualiza.

> Sin el paso 4, el sistema seguirá creyendo que la prenda está en la trastienda y la lista de reposición se llenará de falsos positivos hasta que nadie la mire. **Es el paso que más se olvida y el que hace que esta función funcione o fracase.**

---

## P06 — Venta con RFID

Integrado en el flujo de caja existente, sin pasos adicionales.

1. El cliente deja las prendas en el mostrador.
2. La **antena de campo cercano** bajo el mostrador lee todos los tags a la vez.
3. La caja muestra las prendas detectadas; el cajero confirma.
4. Se cobra normalmente.
5. Al confirmar, el sistema marca esos EPC como `vendido`.
6. Se retiran los hangtags y se entrega la prenda.

### Casos que el cajero debe saber manejar

| Situación | Qué hacer |
|---|---|
| Una prenda no se detecta | Escanear su código de barras. La venta se registra igual; el sistema marca ese SKU para revisión |
| Se detectan prendas de más | Puede haber leído algo cercano. Quitar de la lista lo que el cliente no lleva y volver a leer |
| El sistema RFID está caído | **Vender normalmente por código de barras.** El RFID nunca bloquea una venta |
| El cliente lleva prendas de otra tienda | Aparecerán como EPC desconocidos. Ignorar; no impide la venta |

---

## P07 — Devolución de cliente

1. Leer el tag de la prenda devuelta con el handheld en modo Consulta.
2. El sistema muestra: cuándo se vendió, en qué comprobante, a qué precio.
3. Confirmar la devolución.
4. La prenda vuelve a `en_stock`.

> **El valor real**: verifica que la prenda devuelta es exactamente la que se vendió. Es la defensa contra la devolución de prenda usada o de otra procedencia. Si el tag no corresponde a ninguna venta, el sistema lo dice claramente.

---

## P08 — Transferencia entre tiendas

### En origen

1. Crear la transferencia en la web: tienda destino.
2. Meter las prendas en el bulto.
3. Barrer el bulto con el handheld → los tags quedan en `en_transito`.
4. Precintar y despachar. La guía de remisión se genera con el detalle exacto.

### En destino

1. Abrir la transferencia en la web.
2. Pasar el bulto por el túnel, o barrerlo con el handheld.
3. El sistema compara enviado vs. recibido.
4. Confirmar. Los tags pasan a `en_stock` en la nueva tienda.

> Las prendas que salieron y no llegaron quedan en `en_transito`. Tras 7 días sin recibirse, el sistema genera una alerta. Es la forma de detectar pérdidas en el transporte, que suelen ser invisibles.

---

## P09 — Alarma de portal

Cuando suena la alarma:

```
  Suena la alarma
        │
        ▼
  ┌────────────────────────────────────────┐
  │ Mirar la pantalla del panel de tienda: │
  │ muestra QUÉ prenda y CON QUÉ confianza │
  └───────────────┬────────────────────────┘
                  ▼
       ¿Confianza alta y la persona
        lleva esa prenda visible?
         ┌────────┴────────┐
        Sí                 No
         │                  │
         ▼                  ▼
  ┌──────────────┐   ┌─────────────────────┐
  │ Abordar con  │   │ NO abordar.         │
  │ cortesía:    │   │ Registrar el evento │
  │ "Disculpe,   │   │ como falso positivo │
  │ creo que una │   │ en el panel.        │
  │ prenda no    │   └─────────────────────┘
  │ pasó por     │
  │ caja"        │
  └──────────────┘
```

### Reglas no negociables

1. **Nunca acusar.** La alarma puede fallar. La frase es siempre una pregunta, nunca una afirmación.
2. **Nunca retener físicamente a nadie.** No es competencia del personal de tienda.
3. **Registrar todos los falsos positivos.** Es el único dato que permite calibrar el portal. Un portal con 20 % de falsos positivos se acaba desconectando, y entonces no sirve de nada.
4. Si la confianza es baja (< 70 %), el sistema no debería ni sonar. Si suena mucho con confianza baja, avisar al responsable técnico: hay que recalibrar.

---

## P10 — Búsqueda de prenda concreta

Un cliente pregunta por una talla. El sistema dice que hay una, pero no se encuentra.

1. Handheld → modo Búsqueda.
2. Buscar el producto y la talla; elegir la unidad.
3. Caminar por la tienda. El pitido se acelera al acercarse.
4. Localizada.

Tiempo típico: **1 a 3 minutos**, frente a los 10–20 minutos de buscar a mano, o la venta perdida por decir "no hay".

---

## P11 — Re-etiquetado por tag perdido

Una prenda cuyo hangtag se arrancó.

1. Handheld → modo Consulta. Intentar leer: si no hay respuesta, no hay tag.
2. Buscar el SKU por su código de barras.
3. Handheld → modo Tarado con ese SKU.
4. Colocar una etiqueta nueva y tarar.
5. **Importante**: indicar en la aplicación que es una **sustitución**, no una prenda nueva. Si no, el inventario contará una unidad de más.

> El sistema no puede saber cuál era el EPC anterior. Esa unidad quedará como faltante en el próximo ciclo y se dará por perdida. Es un coste inevitable, y la razón por la que la tasa de tags arrancados es un indicador que se vigila (documento 13).

---

## P12 — Devolución a proveedor

1. Crear la devolución en la web, con el proveedor y el motivo.
2. Barrer las prendas con el handheld.
3. Los tags pasan a `baja` con motivo `devolucion_prov`.
4. El sistema genera el detalle exacto para la nota de crédito.

---

## Calendario operativo recomendado

| Cuándo | Qué |
|---|---|
| **Diario** | Revisar la lista de reposición (P05). Revisar alertas abiertas |
| **Diario, al cierre** | Barrido rápido de probadores y caja (5 min) |
| **Semanal** | Ciclo de inventario completo (P03 + P04) |
| **Semanal** | Revisar falsos positivos del portal |
| **Mensual** | Revisar tags sospechosos (los que aparecen siempre y nunca se venden) |
| **Mensual** | Informe de merma por categoría a gerencia |
| **Trimestral** | Recalibración del portal (documento 03, §4) |
| **Trimestral** | Revisión de la tasa de lectura sobre surtido nuevo |

---

## Formación del personal

| Rol | Duración | Contenido |
|---|---|---|
| Vendedor | 30 min | P05, P06, P07, P09, P10. Práctica con el handheld |
| Almacén | 60 min | P01, P02, P08, P12. Práctica de tarado con 50 prendas |
| Jefe de tienda | 90 min | Todo lo anterior + P03, P04. Interpretación de informes |
| Responsable técnico | 4 h | Documentos 03, 07, 11. Calibración y diagnóstico |

**Material recomendado**: vídeos de 2 minutos por proceso, grabados en la tienda real con el equipo real. Un manual PDF de 40 páginas no lo leerá nadie.
