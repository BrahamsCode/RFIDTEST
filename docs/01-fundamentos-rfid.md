# 01 — Fundamentos técnicos de RFID

> Este documento existe porque **la mayoría de los proyectos RFID fracasan por razones de radiofrecuencia, no de software**. Un equipo de desarrollo que trata el lector como "un escáner de código de barras más rápido" produce un sistema que funciona en la demo y falla en la tienda.

---

## 1. Principio físico

Un sistema RFID pasivo UHF tiene tres elementos:

```
  ┌──────────┐   onda portadora 915 MHz (energía + comandos)   ┌─────────┐
  │  LECTOR  │ ───────────────────────────────────────────────▶│   TAG   │
  │ + antena │                                                  │  chip + │
  │          │ ◀─────────────────────────────────────────────── │ antena  │
  └──────────┘   backscatter (modulación de la onda reflejada)  └─────────┘
```

1. El lector emite una **onda portadora continua**.
2. La antena del tag capta esa energía y la rectifica; alimenta el chip con unos pocos microvatios.
3. El chip modula la impedancia de su antena, cambiando cuánta energía **refleja**. Eso es *backscatter*.
4. El lector detecta esa variación en la onda reflejada y la decodifica como bits.

Consecuencias prácticas de este mecanismo:

| Hecho físico | Consecuencia operativa |
|---|---|
| El tag no tiene batería | El alcance está limitado por cuánta energía llega al tag, no por cuánta transmite |
| El enlace es de ida y vuelta | La energía cae con la 4ª potencia de la distancia (no la 2ª). Duplicar el alcance requiere ~16× la potencia |
| El metal refleja | Un tag pegado directamente sobre metal no se energiza (salvo tags "on-metal" especiales, más caros y gruesos) |
| El agua absorbe a 915 MHz | Prendas mojadas, cuerpo humano interpuesto, o líquidos degradan la lectura severamente |
| La onda rebota | En tiendas con superficies metálicas hay *multipath*: zonas nulas donde no se lee y rebotes que leen tags lejanos |
| La orientación importa | Antena de tag y de lector deben acoplarse. Un tag perpendicular a la polarización lee mucho peor |

### 1.1 Polarización

- **Antena lineal**: mayor alcance, pero exige que el tag esté orientado. Útil en portales y túneles con orientación controlada.
- **Antena circular**: menor alcance (≈3 dB), lee cualquier orientación. **Es la elección por defecto en retail de ropa**, donde las prendas cuelgan y se apilan en cualquier ángulo.

### 1.2 Presupuesto de enlace (link budget) simplificado

```
P_recibida_tag (dBm) = P_tx + G_antena_lector − Pérdida_espacio_libre + G_antena_tag

Pérdida_espacio_libre (dB) = 20·log10(d) + 20·log10(f) − 147.55
   con d en metros y f en Hz
```

Ejemplo a 915 MHz, 3 metros, lector de 30 dBm con antena de 6 dBi, tag con antena de 1.5 dBi:

```
FSPL = 20·log10(3) + 20·log10(915e6) − 147.55 ≈ 9.54 + 179.22 − 147.55 = 41.2 dB
P_tag = 30 + 6 − 41.2 + 1.5 = −3.7 dBm
```

Un chip Gen2 moderno necesita típicamente **−18 a −22 dBm** para encender. Con −3.7 dBm hay margen amplio. A 10 m el FSPL sube a ~51.7 dB y P_tag ≈ −14.2 dBm: sigue funcionando en línea de vista limpia, pero cualquier obstrucción (cuerpo humano, ~10–20 dB) lo tumba.

**Regla de bolsillo**: en tienda real, con prendas apiladas y personas, cuenta con la **mitad** del alcance nominal que declara el fabricante.

---

## 2. El estándar Gen2

**EPCglobal UHF Class 1 Generation 2**, ratificado también como **ISO/IEC 18000-63**. Versión vigente: Gen2v2 (añade seguridad: autenticación, ocultamiento de memoria, `Untraceable`).

### 2.1 Mapa de memoria del tag

Todo tag Gen2 tiene cuatro bancos de memoria:

| Banco | Nº | Contenido | Escribible | Uso en TRAZA |
|---|---|---|---|---|
| **Reserved** | 00 | Kill password (32 bit) + Access password (32 bit) | Sí | Kill password para desactivación en venta (opcional). Access password para bloquear escritura |
| **EPC** | 01 | CRC-16 + PC/XPC + EPC (96–496 bit) | Sí | **El identificador de la prenda**. SGTIN-96 |
| **TID** | 10 | Clase de chip + serial único de fábrica | **No** | Antifalsificación: el TID no se puede clonar en un chip legítimo |
| **User** | 11 | Memoria libre (0–512+ bit según chip) | Sí | Opcional: talla/color redundante, fecha de tarado, ID de tienda |

> **Decisión de diseño TRAZA**: el EPC es la clave. El TID se lee y almacena como **testigo antifraude**, y se compara: si un mismo EPC aparece con dos TID distintos, hay clonación. La memoria User **no se usa** en Fase 1 (encarece el tag y complica el tarado).

### 2.2 Sesiones y flags de inventario

Gen2 define 4 **sesiones** (S0–S3). Cada tag mantiene por sesión un flag `A`/`B`. Cuando el lector inventaría, los tags que responden pasan de A a B y dejan de responder hasta que el flag "decae".

| Sesión | Tiempo de persistencia del flag (energizado) | Uso recomendado |
|---|---|---|
| **S0** | Muy corto (≈ inmediato al perder energía) | Portales / lectura continua de pocos tags. El tag responde una y otra vez |
| **S1** | 500 ms – 5 s | Poblaciones grandes con un solo lector. Buen equilibrio |
| **S2** | > 2 s (persiste sin energía) | **Inventario de mano en tienda** — evita releer lo ya contado al barrer |
| **S3** | > 2 s | Segunda sesión independiente, para un segundo lector simultáneo |

> **Decisión de diseño TRAZA**:
> - Handheld en modo inventario → **S2**, target `A`, con `Toggle` deshabilitado en pasadas cortas.
> - Portal de salida → **S0** o **S1**, para que el tag reporte repetidamente mientras cruza.
> - Túnel de recepción → **S1**, con `Q` ajustado a la población esperada.

### 2.3 Anticolisión: el parámetro Q

Gen2 usa **Slotted ALOHA**. El lector anuncia `2^Q` ranuras; cada tag elige una al azar y responde en ella.

- `Q` demasiado bajo con muchos tags → colisiones constantes, rendimiento colapsa.
- `Q` demasiado alto con pocos tags → ranuras vacías, se desperdicia tiempo.

`Q` óptimo ≈ `log2(número de tags en el campo)`.

| Tags esperados en campo | Q recomendado |
|---|---|
| 1–4 | 2 |
| 5–15 | 3–4 |
| 16–60 | 5 |
| 60–250 | 6–7 |
| 250–1000 | 8–9 |
| > 1000 | 10 + reducir potencia y barrer por zonas |

La mayoría de lectores modernos hacen **Q dinámico**. Aun así, TRAZA expone `q_inicial` como parámetro por perfil de lectura, porque en un almacén con 3 000 prendas apiladas el arranque automático es lento.

### 2.4 Rendimiento realista

| Escenario | Tasa de lectura típica |
|---|---|
| Handheld barriendo estantería, tags bien orientados | 400–900 tags/s |
| Handheld sobre pila densa de jeans | 100–300 tags/s |
| Portal, persona caminando a 1 m/s | 30–100 tags detectados por tránsito |
| Túnel de recepción, caja de 40 prendas | 100 % en 2–4 s |

**Exactitud de lectura (read rate) esperable en inventario de tienda**: 97–99.5 % por pasada. Se llega a >99.9 % con **dos o tres pasadas** desde ángulos distintos. Por eso el proceso de inventario de TRAZA incluye siempre una segunda pasada de las zonas con desviación.

---

## 3. Regulación en Perú

En Perú, el espectro está regulado por el Ministerio de Transportes y Comunicaciones (MTC) a través del **Plan Nacional de Atribución de Frecuencias (PNAF)**. <cite index="4-1">El PNAF contiene los cuadros de atribución de frecuencias de los distintos servicios de telecomunicaciones, de modo que operen en bandas definidas previamente, asegurando su operatividad y minimizando interferencias perjudiciales</cite>. <cite index="7-1">La versión vigente fue aprobada por Resolución Ministerial N° 0597-2023-MTC/01.03</cite>.

Para equipos de baja potencia en las bandas ISM, la norma de referencia es la <cite index="1-1">Resolución Ministerial N° 777-2005-MTC/03, cuyo anexo establece las condiciones de operación de los servicios cuyos equipos utilizan, entre otras, las bandas 915–928 MHz y 902–928 MHz, modificado posteriormente por la Resolución Ministerial N° 199-2013-MTC/03</cite>.

### 3.1 Implicancias operativas

| Punto | Detalle |
|---|---|
| **Banda de trabajo** | Región 2 / ISM 902–928 MHz. Configurar el lector en perfil **FCC / Región 2** (no ETSI). El sub-rango efectivamente autorizado debe confirmarse contra el anexo vigente |
| **Homologación** | Todo equipo de telecomunicaciones comercializado o usado en Perú requiere **homologación ante el MTC**. Compra a distribuidores que entreguen el certificado, o gestiona el trámite |
| **Licencia** | Los equipos de baja potencia en banda ISM operan típicamente **sin licencia individual**, sujetos a límites de potencia y a no reclamar protección contra interferencias |
| **Potencia** | Los lectores FCC-región llegan a 30–33 dBm (1–2 W). El límite de EIRP aplicable debe verificarse en el anexo del MTC |

> ⚠️ **VERIFICAR ANTES DE COMPRAR**
> 1. Sub-banda exacta y límite de potencia (EIRP/ERP) vigentes en el anexo de la RM 777-2005-MTC/03 con sus modificatorias.
> 2. Que el modelo concreto de lector figure homologado por el MTC, o presupuestar el trámite.
> 3. Si vas a importar directo desde Asia, la homologación es tu responsabilidad y puede costar más y tardar más que el equipo.
>
> Consultar: `https://pnaf.mtc.gob.pe/` y la Dirección General de Autorizaciones en Telecomunicaciones (DGAT).

### 3.2 Por qué esto no es un trámite ignorable

Un lector configurado en perfil ETSI (865–868 MHz, Europa) **no leerá** tags optimizados para 902–928 MHz con buen rendimiento, y estará emitiendo en una banda atribuida a otros servicios en Perú. Es un error de configuración con consecuencias técnicas *y* legales. En TRAZA, el perfil regional es un parámetro obligatorio del registro de dispositivo y el sistema **rechaza** dar de alta un lector sin perfil regulatorio declarado.

---

## 4. Tipos de tag para indumentaria

### 4.1 Formatos

| Formato | Descripción | Ventaja | Inconveniente |
|---|---|---|---|
| **Hangtag RFID** | Etiqueta colgante de cartón con inlay dentro | Fácil de aplicar, se retira en venta, imprimible con precio y código de barras | Se puede arrancar; no acompaña al producto tras la venta |
| **Care label / etiqueta de composición** | Inlay cosido dentro de la etiqueta textil | Permanente; útil para devoluciones y garantía | Debe aplicarse en confección (origen); no retirable |
| **Etiqueta adhesiva** | Inlay en adhesivo | Barato | Se despega; mal sobre tejido |
| **Tag duro reutilizable** | Carcasa plástica con broche, tipo alarma | Reutilizable, resistente | Coste alto por unidad; requiere desmontador en caja |
| **On-metal** | Con separador dieléctrico | Funciona sobre metal | Caro y voluminoso; solo para accesorios metálicos |

> **Decisión de diseño TRAZA (Fase 1)**: **hangtag RFID impreso en tienda/almacén propio**. Razón: en Gamarra y en confección local no se puede exigir al proveedor que etiquete en origen. El tarado se hace al recibir, en el mismo acto que el etiquetado de precio.
>
> **Fase 3 (proveedores integrados)**: migrar a `source tagging` — el proveedor recibe rangos EPC asignados y etiqueta en su planta. Elimina la operación más cara del sistema.

### 4.2 Selección de inlay

| Criterio | Recomendación |
|---|---|
| **Chip** | Impinj M730/M750, o NXP UCODE 8/9. Sensibilidad ≤ −22 dBm |
| **Tamaño** | 44 × 24 mm o similar; los inlays "mini" pierden alcance notablemente |
| **Sensibilidad** | Priorizar sobre alcance nominal: un tag sensible funciona en pila densa |
| **Certificación** | Buscar inlays con calificación **ARC (Auburn University RFID Lab)** para categoría *apparel* |
| **Pre-codificado** | Comprar con EPC ya serializado si no vas a imprimir. Elimina la impresora del BOM inicial |

### 4.3 El problema de la pila densa

Un montón de 50 jeans apilados es el peor caso: la humedad residual del algodón absorbe, los tags se apantallan entre sí, y los remaches metálicos reflejan. Mitigaciones:

1. Colocar el hangtag **siempre en el mismo punto** de la prenda (etiqueta de cintura, lado exterior). La consistencia es más valiosa que la posición óptima.
2. En inventario, **abanicar** la pila o barrer desde dos lados.
3. Bajar potencia y hacer múltiples pasadas cortas antes que una pasada larga a máxima potencia.

---

## 5. Lecturas fantasma: el enemigo número uno

Una "lectura fantasma" es un tag detectado que no está en la zona que crees. Fuentes:

| Fuente | Ejemplo | Mitigación |
|---|---|---|
| **Exceso de alcance** | El handheld en sala lee la trastienda a través del tabique de drywall | Reducir potencia; definir perfiles por zona |
| **Multipath** | Rebote en una columna metálica hace que un tag a 12 m aparezca | Umbral de RSSI; antenas direccionales |
| **Tag en tránsito** | Un cliente pasa por delante del portal sin salir | Ventana temporal + secuencia de antenas (dirección de cruce) |
| **Tienda vecina** | En una galería de Gamarra, el local de al lado también tiene RFID | Reducir potencia, apantallar, coordinar canales, usar zonas horarias de conteo |
| **Tag huérfano** | Etiqueta arrancada que quedó en el suelo | Reconciliación: si el EPC no se ve en N ciclos → estado `perdido` |

### 5.1 Estrategia de filtrado de TRAZA

El middleware aplica un pipeline en cascada antes de que una lectura llegue al dominio de negocio:

```
lectura cruda
   │
   ├─▶ [1] Filtro de máscara EPC     ¿el prefijo corresponde a nuestra empresa?
   │                                  descarta tags ajenos, pallets, tarjetas
   ├─▶ [2] Umbral de RSSI            ¿supera el mínimo del perfil de esta antena?
   │
   ├─▶ [3] Deduplicación por ventana ¿ya vimos este EPC en los últimos N ms?
   │
   ├─▶ [4] Conteo mínimo             ¿lo vimos al menos K veces en la ventana?
   │
   ├─▶ [5] Regla de dirección        (solo portales) ¿la secuencia de antenas
   │                                  indica entrada o salida?
   └─▶ evento de dominio limpio
```

Los parámetros `[2]` a `[5]` son **por antena y por perfil**, configurables sin desplegar código. Esto no es sobre-ingeniería: son los números que se ajustan durante la puesta en marcha y que cambian cuando se mueve un mueble.

---

## 6. Comparativa con tecnologías alternativas

| | Código de barras | **RFID UHF** | NFC (HF 13.56 MHz) | BLE / beacons | Visión por computadora |
|---|---|---|---|---|---|
| Línea de vista | Requerida | No | No (pero ~4 cm) | No | Requerida |
| Alcance | ~30 cm | 3–10 m | 1–10 cm | 10–50 m | Depende de cámara |
| Lectura múltiple | No | Sí (cientos/s) | No (una a una) | Sí | Parcial |
| Identificación única por unidad | No (solo SKU) | **Sí** | Sí | Sí | No |
| Coste por unidad etiquetada | ~USD 0.005 | **USD 0.05–0.15** | USD 0.15–0.50 | USD 2–15 | 0 (sin etiqueta) |
| Necesita batería en la etiqueta | No | No | No | **Sí** | — |
| Madurez en retail de ropa | Total | **Alta** | Nicho (lujo/autenticación) | Baja | Emergente |

**Conclusión**: para control de stock de indumentaria, RFID UHF pasivo es la única tecnología con la combinación correcta de coste por unidad, lectura masiva e identificación unitaria. NFC se reserva para autenticación de producto premium; BLE para activos de alto valor; visión por computadora es complementaria (detección de hueco en góndola), no sustitutiva.

---

## 7. Errores clásicos que este proyecto debe evitar

1. **Comprar hardware antes de hacer un piloto.** Ver documento 14, Fase 0.
2. **Tratar el EPC como un SKU.** El EPC es *serializado*: identifica la unidad, no el modelo. Todo el modelo de datos depende de entender esto (documento 04).
3. **No guardar las lecturas crudas.** Cuando algo no cuadre, el registro crudo con RSSI y antena es la única forma de diagnosticar. Se particiona y se purga, pero se guarda.
4. **Máxima potencia siempre.** Produce más lecturas fantasma que aciertos. La potencia es un parámetro por zona que se calibra.
5. **Ignorar el proceso humano.** Si el reponedor no barre el probador, el inventario tendrá un agujero constante que nadie explicará.
6. **Un solo inventario "definitivo".** El valor está en el ciclo: contar seguido y barato, no contar perfecto una vez.
7. **Asumir que el tag sobrevive a la operación.** Los hangtags se arrancan. El sistema debe manejar el estado `sin_tag` como un caso de negocio normal, no como un error.
