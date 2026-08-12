# 03 — Hardware, lista de materiales y despliegue físico

> ⚠️ Todos los precios de este documento son **referenciales** y en USD, orientativos para compra en Latinoamérica en 2026. Varían ±40 % según distribuidor, volumen, y si se importa directo. **Cotizar antes de presupuestar.** No incluyen IGV (18 %), aranceles ni homologación MTC.

---

## 1. Catálogo de dispositivos

### 1.1 Lector de mano (handheld) — el equipo imprescindible

Es el único hardware verdaderamente indispensable. Con un handheld y tags ya se obtiene el 80 % del valor del sistema.

| Modelo | Tipo | Notas | Precio ref. |
|---|---|---|---|
| **Zebra RFD40 / RFD90** + TC22/TC53 | Empuñadura + terminal Android | Estándar de la industria. RFD90 = largo alcance (hasta ~20 m). SDK maduro (RFID API3). Ecosistema de servicio en Perú | USD 2 500 – 4 500 |
| **Zebra MC3390R / MC3300R** | Todo en uno, tipo pistola | Robusto, teclado físico. Descontinuado en algunas variantes; verificar soporte | USD 3 000 – 4 000 |
| **Chainway C72 / C66** | Todo en uno Android | **Mejor relación precio/prestaciones.** SDK propio en Java, funcional aunque menos pulido | USD 1 100 – 1 900 |
| **Honeywell IH45 / CT47 + RFID** | Empuñadura modular | Buen soporte corporativo | USD 2 200 – 3 500 |
| **CipherLab RS35 / RK25 UHF** | Compacto | Alternativa económica | USD 1 200 – 2 000 |

**Criterios de selección obligatorios:**

1. **SDK Android nativo documentado y descargable sin contrato.** Si el fabricante te pide firmar un NDA para ver la documentación, descártalo.
2. **Perfil de región configurable a FCC / 902–928 MHz.**
3. **Batería intercambiable en caliente.** Un inventario de tienda grande agota una batería.
4. **Soporte o repuestos en Lima.** Un equipo sin servicio local es un equipo muerto al primer golpe.
5. **Gatillo físico.** Los operarios barren durante 40 minutos; un botón en pantalla es inaceptable.

> **Recomendación para Fase 0**: **Chainway C72**. Es la forma más barata de comprobar si RFID funciona sobre tu surtido real. Si el piloto valida, escalar a Zebra para el despliegue por robustez y servicio.

---

### 1.2 Lector fijo (portal antihurto y control de puertas)

| Modelo | Puertos de antena | Notas | Precio ref. |
|---|---|---|---|
| **Zebra FX9600** | 4 u 8 | Referencia del mercado. LLRP completo, soporta DRM | USD 1 800 – 2 800 |
| **Impinj Speedway R420 / R700** | 4 | Excelente sensibilidad. R700 admite aplicaciones embebidas | USD 1 600 – 2 900 |
| **Chainway UR4 / UR8** | 4 u 8 | Económico, LLRP soportado | USD 700 – 1 300 |
| **Zebra ATR7000** | Integrado (array) | Localización en tiempo real por ángulo. Caro, para casos avanzados | USD 5 000+ |

### 1.3 Antenas

| Tipo | Ganancia | Uso |
|---|---|---|
| Panel circular 8–9 dBic | 8–9 dBic | **Portal de salida.** Dos por lado, a 0.6 m y 1.6 m de altura |
| Panel circular 6 dBic | 6 dBic | Túnel de recepción, probadores |
| Antena de campo cercano | ~1 dBic | Punto de caja: lee solo lo que está encima del mostrador. **Clave para evitar sobre-lectura en caja** |
| Antena lineal 9 dBi | 9 dBi | Cintas transportadoras con orientación fija |

Precio referencial por antena: **USD 90 – 280**. Cable coaxial LMR-195/LMR-240 con conector RP-TNC: **USD 25 – 70** por tramo.

> **Error caro y frecuente**: comprar cable barato o demasiado largo. Cada 3 dB de pérdida en cable divide la potencia por 2. Un tramo de 10 m de cable malo puede costarte la mitad del alcance.

### 1.4 Impresora RFID (codificadora)

Solo necesaria si etiquetas en casa (que es el caso en Fase 1).

| Modelo | Notas | Precio ref. |
|---|---|---|
| **Zebra ZT411 RFID** (o ZT421 para etiquetas anchas) | Estándar. ZPL con comandos RFID (`^RF`, `^RS`). Verifica y descarta tags defectuosos automáticamente | USD 2 800 – 4 200 |
| **Zebra ZD621R** | Sobremesa, menor volumen | USD 1 600 – 2 400 |
| **Honeywell PM45 RFID** | Alternativa industrial | USD 2 500 – 3 800 |

> **Alternativa Fase 0/1 que ahorra USD 3 000**: comprar **hangtags RFID pre-codificados** con EPC serializado de fábrica, e imprimir el precio en una impresora térmica normal (o a mano). El tarado se hace leyendo el EPC con el handheld y asociándolo al SKU. Es más lento por unidad, pero elimina la impresora del presupuesto inicial. **Recomendado hasta superar ~3 000 prendas/mes.**

### 1.5 Túnel / cabina de recepción

Recinto con material absorbente de RF y 4–8 antenas internas, para leer bultos completos.

| Opción | Precio ref. |
|---|---|
| Túnel comercial llave en mano | USD 8 000 – 25 000 |
| **Construcción propia**: estructura + lector 4 puertos + 4 antenas + espuma absorbente RF | USD 2 500 – 4 500 |

> Para volúmenes de Gamarra, un "túnel" hecho de una caja de melamina forrada con espuma absorbente y 4 antenas cumple perfectamente. No compres uno importado en Fase 1.

### 1.6 Computador de borde (`traza-edge`)

| Opción | Notas | Precio ref. |
|---|---|---|
| **Mac mini M4 16 GB** | Ya en uso por el equipo. Sobrado. Docker vía OrbStack | USD 700 – 900 |
| **Intel NUC / mini-PC N100 16 GB** | Suficiente y barato. Linux + Docker | USD 250 – 400 |
| **Raspberry Pi 5 8 GB + SSD** | Funciona para 1–2 lectores. Vigilar térmica | USD 130 – 200 |

> Recomendado por tienda: **mini-PC N100**. Bajo consumo, sin ventilador ruidoso, Docker nativo, coste bajo para replicar en N tiendas.

### 1.7 Tags / etiquetas

| Formato | Precio ref. por unidad (volumen ≥ 10 000) |
|---|---|
| Inlay en rollo, sin imprimir (para tu impresora) | USD 0.04 – 0.09 |
| Hangtag RFID pre-codificado e impreso | USD 0.08 – 0.18 |
| Etiqueta de composición RFID (cosida) | USD 0.10 – 0.20 |
| Tag duro reutilizable | USD 0.80 – 2.50 |

Proveedores a cotizar: Avery Dennison, Checkpoint, SML, Nedap, Zebra, y fabricantes chinos (Shenzhen) con MOQ altos pero precio mínimo. En Perú, distribuidores de auto-ID en Lima (zona de Miraflores / San Isidro / Ate).

---

## 2. Lista de materiales (BOM) por fase

### Fase 0 — Piloto de validación (1 tienda, sin obra)

| Ítem | Cant. | Unit. USD | Total USD |
|---|---:|---:|---:|
| Handheld Chainway C72 | 1 | 1 400 | 1 400 |
| Batería adicional | 1 | 90 | 90 |
| Hangtags RFID pre-codificados | 3 000 | 0.12 | 360 |
| Muestras de inlays (3 modelos, para probar sobre tu surtido) | 300 | 0.15 | 45 |
| Mini-PC N100 (edge + servidor de pruebas) | 1 | 320 | 320 |
| Access point Wi-Fi 5 GHz | 1 | 90 | 90 |
| Fungibles (precintos, soportes, cinta) | — | — | 80 |
| **Subtotal Fase 0** | | | **≈ 2 385** |

### Fase 1 — Tienda completa operativa

| Ítem | Cant. | Unit. USD | Total USD |
|---|---:|---:|---:|
| Handheld Zebra RFD40 + TC22 | 2 | 3 200 | 6 400 |
| Impresora Zebra ZT411 RFID | 1 | 3 400 | 3 400 |
| Lector fijo FX9600 (portal salida) | 1 | 2 300 | 2 300 |
| Antenas panel circular 9 dBic | 4 | 180 | 720 |
| Cable coaxial + conectores | 4 | 45 | 180 |
| Estructura de portal (pedestales / marco) | 1 | 600 | 600 |
| Antena de campo cercano (caja) | 1 | 220 | 220 |
| Lector 4 puertos para túnel de recepción (Chainway UR4) | 1 | 950 | 950 |
| Antenas 6 dBic para túnel | 4 | 130 | 520 |
| Estructura + absorbente RF del túnel (fabricación local) | 1 | 900 | 900 |
| Mini-PC edge | 1 | 320 | 320 |
| Switch gestionado 8 puertos PoE | 1 | 150 | 150 |
| Inlays en rollo (stock inicial) | 25 000 | 0.06 | 1 500 |
| Instalación eléctrica y de red | — | — | 700 |
| Homologación MTC (estimado) ⚠️ | — | — | 800 |
| **Subtotal Fase 1** | | | **≈ 19 660** |

### Fase 2 — Réplica por tienda adicional

| Ítem | Cant. | Total USD |
|---|---:|---:|
| Handheld | 2 | 6 400 |
| Portal (lector + 4 antenas + estructura + cable) | 1 | 3 800 |
| Edge + red | 1 | 470 |
| Antena de caja | 1 | 220 |
| Instalación | — | 600 |
| **Por tienda adicional** | | **≈ 11 490** |

> La impresora y el túnel se centralizan en el almacén; no se replican por tienda.

---

## 3. Site survey: el paso que nadie hace y todos lamentan

Antes de instalar cualquier equipo fijo, se realiza un levantamiento con este protocolo.

### 3.1 Checklist de levantamiento

```
[ ] Plano de planta a escala, con cotas
[ ] Marcar: puertas, probadores, caja, trastienda, montantes metálicos,
    ascensores, cuadros eléctricos, tuberías
[ ] Medir ancho exacto de la puerta de salida (define nº de antenas del portal)
[ ] Identificar la separación con el local vecino y de qué material es
    (drywall = transparente a RF; ladrillo/concreto = atenúa; metal = refleja)
[ ] Fotografiar el techo: ¿hay cielo raso registrable para pasar cable?
[ ] Ubicar tomas eléctricas disponibles cerca de cada punto de lectura
[ ] Medir cobertura Wi-Fi 5 GHz en todos los rincones (incluido probador y
    almacén). Con menos de −70 dBm el handheld se desconecta
[ ] Barrido de espectro 902–928 MHz para detectar interferencias
    (herramienta: analizador de espectro económico tipo TinySA)
[ ] Contar la densidad máxima de prendas por metro lineal de exhibidor
[ ] Inventariar exhibidores: ¿son metálicos? ¿espejos? (los espejos con
    respaldo metálico reflejan RF)
```

### 3.2 Prueba de tasa de lectura sobre surtido real

**Este es el experimento que decide si el proyecto sigue.**

Procedimiento:

1. Seleccionar **100 prendas representativas** del surtido, cubriendo el peor caso: jeans con remaches, prendas con lentejuelas o hilos metálicos, ropa de baño con elastano, prendas apiladas.
2. Etiquetar las 100 con el mismo inlay, en la **misma posición** de la prenda.
3. Colocarlas como estarían en tienda: dobladas en pila, colgadas en rack, en cajón.
4. Barrer con el handheld a potencia media (25 dBm) durante 15 s, tres veces.
5. Registrar el número de EPC únicos leídos en cada pasada.

Criterios de aceptación:

| Resultado | Interpretación |
|---|---|
| ≥ 98 % en 1 pasada | Excelente. Adelante |
| 95–98 % en 1 pasada, ≥ 99.5 % en 3 | Normal. Adelante, con proceso de doble pasada |
| 90–95 % en 1 pasada | Revisar inlay y posición del tag antes de continuar |
| < 90 % | **Detener.** Hay un problema de material o de inlay. Probar otro modelo de inlay antes de comprometer más presupuesto |

Repetir la prueba con al menos **3 modelos de inlay distintos**. La diferencia entre un buen inlay y uno mediocre en pila densa puede ser de 15 puntos porcentuales.

---

## 4. Diseño físico del portal de salida

```
   VISTA EN PLANTA                      VISTA FRONTAL (un pedestal)
   ┌───────────────────────┐
   │        TIENDA          │              ┌────────┐
   │                        │              │        │
   │   ▓ANT1        ANT3▓   │  ← 1.6 m     │  ▓▓▓▓  │ ← Antena alta  (1.6 m)
   │   ▓ANT2        ANT4▓   │  ← 0.6 m     │        │
   │   │                │   │              │  ▓▓▓▓  │ ← Antena baja  (0.6 m)
   │   └── 1.2 – 2.0 m ─┘   │              │        │
   │      zona de paso      │              │  ████  │ ← Base / lector
   ├────────────────────────┤              └────────┘
   │        CALLE           │
   └────────────────────────┘
```

### Parámetros de instalación

| Parámetro | Valor recomendado |
|---|---|
| Ancho de paso entre pedestales | ≤ 2.0 m (por encima, añadir antenas o un tercer pedestal) |
| Altura antena superior | 1.5 – 1.7 m |
| Altura antena inferior | 0.5 – 0.7 m |
| Inclinación | 10–15° hacia el centro del paso |
| Potencia inicial | 24–27 dBm, ajustar en calibración |
| Sesión Gen2 | S0 o S1 |
| Separación mínima de la caja | 3 m (evita leer prendas en proceso de pago) |

### Calibración del portal

1. Colocar 20 tags a 3 m del portal, **dentro** de la tienda, quietos.
2. Subir potencia hasta que se lean. Ese es el **límite superior**: bajar 3 dB por debajo.
3. Caminar cruzando el portal con 1, 5 y 20 tags a distintas alturas y velocidades. Registrar la tasa de detección.
4. Objetivo: **≥ 99 % de detección en cruce** y **0 lecturas de tags estáticos a > 2.5 m**.
5. Si ambos objetivos no se cumplen simultáneamente, el problema es de geometría de antena, no de potencia. Reorientar antes de subir potencia.

---

## 5. Distribución en el almacén / trastienda

```
  ┌──────────────────────────────────────────────────────────┐
  │  ALMACÉN                                                  │
  │                                                            │
  │  ┌────────────┐        ┌──────────────────────────┐        │
  │  │  RECEPCIÓN │        │   ESTANTERÍA DE STOCK    │        │
  │  │            │        │   (barrido con handheld) │        │
  │  │  ┌──────┐  │        │  ═════════════════════   │        │
  │  │  │TÚNEL │  │        │  ═════════════════════   │        │
  │  │  │ RFID │  │        │  ═════════════════════   │        │
  │  │  └──────┘  │        └──────────────────────────┘        │
  │  │            │                                            │
  │  │ ┌────────┐ │        ┌──────────────┐                    │
  │  │ │IMPRESORA│ │        │ traza-edge   │  Rack de red      │
  │  │ │  RFID   │ │        │ + switch     │                    │
  │  │ └────────┘ │        └──────────────┘                    │
  │  └────────────┘                                            │
  │        │                                                    │
  │        ▼  puerta a sala (opcional: portal de trastienda)    │
  └────────────────────────────────────────────────────────────┘
```

> **Portal de trastienda (opcional, alto valor)**: un portal en la puerta almacén↔sala permite saber automáticamente qué está *en piso de venta* y qué está *en stock trasero*. Habilita la alerta de reposición, que es uno de los KPI de mayor retorno. Coste incremental: ≈ USD 3 000. Recomendado en Fase 2.

---

## 6. Consideraciones específicas para Gamarra

El emporio comercial de Gamarra tiene condiciones que rompen los supuestos de los manuales de RFID escritos para centros comerciales:

| Condición | Impacto | Mitigación |
|---|---|---|
| Locales muy juntos, tabiques delgados | Sobre-lectura del local vecino, y el vecino leyendo tu stock | Potencia baja + máscara de filtro EPC estricta + conteo por zonas |
| Estructuras metálicas densas (galerías) | Multipath severo, zonas muertas | Antenas circulares, múltiples pasadas, evitar antenas de alta ganancia |
| Alta rotación y volumen | Tarado manual se vuelve cuello de botella | Priorizar `source tagging` con confeccionistas propios |
| Energía eléctrica inestable | Reinicios de lector, corrupción de datos | UPS obligatorio para edge y lector; buffer local en SQLite |
| Internet intermitente | Sincronización interrumpida | Arquitectura offline-first (ya contemplada) |
| Personal con rotación alta | Curva de aprendizaje repetida | UX del handheld extremadamente simple; formación en video de 10 min |
| Riesgo de robo del equipo | Handheld de USD 3 000 en mostrador | Anclaje físico, cifrado del dispositivo, MDM con borrado remoto |

> **Recomendación estratégica**: si operas confección propia o tienes proveedores estables, el mayor retorno del proyecto no está en la tienda sino en **etiquetar en el taller de confección**. Convierte el tarado de un coste operativo recurrente en un paso del proceso productivo.

---

## 7. Proveedores y homologación en Perú

| Necesidad | Dónde buscar |
|---|---|
| Lectores Zebra/Honeywell | Distribuidores autorizados en Lima (San Isidro, Miraflores, Surco). Pedir certificado de homologación MTC |
| Tags e inlays a volumen | Importación directa (Alibaba/Shenzhen) con MOQ 50 000+, o distribuidores locales con MOQ bajo y precio mayor |
| Impresoras Zebra | Mismo canal que lectores; verificar disponibilidad de cabezal de repuesto |
| Instalación y cableado | Integradores de auto-ID locales, o electricista + supervisión propia |
| Homologación MTC | Dirección General de Autorizaciones en Telecomunicaciones (DGAT). Puede gestionarlo el distribuidor si el modelo ya está homologado |
| Códigos GTIN | **GS1 Perú** — necesario si vas a usar SGTIN estándar (ver documento 04) |

> ⚠️ **VERIFICAR**: si el equipo ya está homologado por el fabricante o distribuidor bajo su propio certificado, no necesitas trámite individual. Confírmalo por escrito antes de comprar. Un lector no homologado importado directamente puede quedar retenido en aduana.

---

## 8. Plan de mantenimiento de hardware

| Elemento | Frecuencia | Acción |
|---|---|---|
| Batería de handheld | Mensual | Verificar salud; reemplazar bajo 80 % de capacidad |
| Cabezal de impresora | Cada 50 000 etiquetas | Limpieza con alcohol isopropílico; reemplazo a ~1 M |
| Conectores de antena | Trimestral | Verificar apriete; la humedad de Lima oxida conectores |
| Calibración del portal | Trimestral, o tras mover mobiliario | Repetir el protocolo de §4 |
| Firmware de lectores | Semestral | Actualizar en ventana de mantenimiento, nunca en horario comercial |
| Limpieza de antenas | Semestral | Polvo y pelusa textil degradan poco, pero la inspección detecta cables sueltos |
| Prueba de tasa de lectura | Semestral, y ante cambio de proveedor de tags | Protocolo de §3.2 |
