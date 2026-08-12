# 13 — KPI, analítica y tableros

Vistas y funciones de apoyo: `sql/vistas-analiticas.sql`

---

## 1. Los indicadores que importan

No todos los números son iguales. Estos cinco son los que justifican la inversión; el resto son diagnóstico.

### 1.1 Exactitud de inventario (*inventory accuracy*)

**El indicador maestro.** Es la razón de ser del sistema.

```
exactitud_unidades = 1 − ( Σ |contado_sku − esperado_sku| / Σ esperado_sku )
```

> **Ojo con la fórmula que se elige.** El error clásico es medir `total_contado / total_esperado`. Si a un SKU le faltan 5 unidades y a otro le sobran 5, esa fórmula da 100 % de exactitud cuando en realidad hay 10 registros equivocados. La fórmula correcta usa el **valor absoluto por SKU**, que es lo que refleja la realidad operativa.

| Situación | Valor típico |
|---|---|
| Antes de RFID (conteo manual, anual) | 60–75 % |
| Primeros 3 ciclos con RFID | 85–93 % (aflora el desorden acumulado) |
| Régimen estable, ciclos semanales | **97–99 %** |
| Objetivo del proyecto | ≥ 97 % sostenido a 6 meses |

### 1.2 Merma (*shrinkage*)

```
merma_% = valor_perdido_periodo / valor_ventas_periodo × 100
```

Se desglosa por causa, que es lo que RFID permite y el conteo manual no:

| Causa | Cómo se identifica |
|---|---|
| Hurto externo | Alarma de portal, o desaparición en zona de sala |
| Hurto interno | Desaparición en trastienda, o patrón por turno/usuario |
| Error administrativo | Diferencia en recepción, transferencias descuadradas |
| Daño | Declarado explícitamente |
| Desconocida | El resto. **Reducir este cajón es el objetivo** |

> Antes de RFID, casi toda la merma es "desconocida". El primer logro real del sistema no es reducir la merma, sino **poder clasificarla**. Una vez clasificada, se puede atacar.

### 1.3 Disponibilidad en sala (*on-floor availability*)

```
disponibilidad = SKU con ≥1 unidad en sala / SKU activos con stock en la tienda
```

Mide la venta perdida invisible: tienes el producto, pero el cliente no lo encuentra porque está en la trastienda.

| Valor | Interpretación |
|---|---|
| < 80 % | Reposición deficiente. Es dinero dejado sobre la mesa a diario |
| 85–92 % | Normal sin proceso de reposición guiado |
| **> 95 %** | Objetivo con el proceso P05 funcionando |

### 1.4 Tiempo de ciclo de inventario

```
minutos_por_millar = duración_ciclo_min / (unidades_contadas / 1000)
```

| Valor | Interpretación |
|---|---|
| > 4 min/millar | Algo va mal: potencia, técnica de barrido o dificultad del material |
| 2–3 min/millar | Normal |
| < 2 min/millar | Excelente, o el operario está barriendo demasiado rápido y perdiendo lecturas |

> Cuando este indicador baja **y** la exactitud baja a la vez, alguien está corriendo. Se miran siempre juntos.

### 1.5 Tasa de lectura por ciclo

```
tasa_lectura = encontrados / esperados
```

Distingue el problema técnico del problema de negocio. Una tasa baja **no** significa que falte mercadería: significa que no la leíste. Si la tasa cae de 98 % a 89 % de un ciclo a otro sin que haya cambiado nada del negocio, el problema es de RF, no de inventario.

---

## 2. Indicadores secundarios

| Indicador | Fórmula | Para qué sirve |
|---|---|---|
| **Antigüedad de stock** | Distribución por tramos de días desde el tarado | Decidir rebajas y liquidaciones con dato, no con intuición |
| **Rotación por SKU** | Unidades vendidas / stock medio del periodo | Compras |
| **Tags perdidos o arrancados** | Sustituciones / unidades taradas | Si sube, hay que revisar el tipo o la posición de la etiqueta |
| **Falsos positivos de portal** | Alarmas descartadas / alarmas totales | > 20 % significa que el portal se acabará ignorando. Recalibrar |
| **Exactitud de recepción** | Recibido / esperado por orden | Evaluación de proveedores con evidencia |
| **Prendas nunca vistas** | Tags `codificado` que nunca pasaron a `en_stock` | Tarado incompleto o mercadería que nunca llegó a sala |
| **Cobertura de tarado** | Unidades con EPC / unidades en el sistema | Mide el avance de la implantación |
| **Tiempo de localización** | Duración media del modo búsqueda | Beneficio directo para el vendedor |
| **Salud de dispositivos** | % del tiempo con lectores conectados | Fiabilidad de la infraestructura |

---

## 3. Tableros

### 3.1 Tablero de tienda (pantalla en trastienda, siempre visible)

```
┌──────────────────────────────────────────────────────────────────┐
│  TIENDA GAMARRA 1                              lunes 10 ago 14:22 │
├────────────────────────────┬─────────────────────────────────────┤
│  EXACTITUD                 │  REPOSICIÓN PENDIENTE               │
│                            │                                      │
│        98.2 %              │        14 SKU                        │
│   último ciclo: 8 ago      │   sin stock en sala, con almacén     │
│   ▲ +0.7 vs anterior       │   ▲ 3 de los más vendidos            │
├────────────────────────────┼─────────────────────────────────────┤
│  UNIDADES EN TIENDA        │  ALERTAS ABIERTAS                   │
│                            │                                      │
│       18 240               │          3                           │
│   sala 11 902 · almacén    │   1 salida no vendida                │
│   6 338                    │   2 EPC desconocidos                 │
├────────────────────────────┴─────────────────────────────────────┤
│  PRÓXIMO CICLO: miércoles 12 · último hace 2 días                 │
│  DISPOSITIVOS: ● Portal  ● Handheld 1  ○ Handheld 2 (batería 12%) │
└──────────────────────────────────────────────────────────────────┘
```

Diseñado para verse a 3 metros. Cuatro números grandes, nada más.

### 3.2 Tablero de gerencia (mensual)

| Panel | Contenido |
|---|---|
| Exactitud por tienda | Serie temporal de los últimos 12 ciclos, una línea por tienda |
| Merma valorizada | Barras apiladas por causa y mes |
| Disponibilidad en sala | Comparativa entre tiendas |
| Antigüedad de stock | Distribución por tramos y por categoría |
| Top 20 SKU con más merma | Tabla con valor acumulado |
| Coste del sistema vs. merma evitada | El panel que decide si el proyecto continúa |

### 3.3 Tablero técnico

Cubierto en el documento 11, §7.

---

## 4. Consultas de los KPI

### Exactitud por tienda, últimos 12 ciclos

```sql
SELECT
    location_name,
    code,
    closed_at::date       AS fecha,
    expected_count        AS esperados,
    counted_count         AS contados,
    read_accuracy_pct     AS tasa_lectura,
    unit_accuracy_pct     AS exactitud,
    ROUND(duration_minutes::numeric, 1) AS minutos,
    ROUND((duration_minutes / NULLIF(counted_count, 0) * 1000)::numeric, 2)
        AS min_por_millar
FROM v_inventory_accuracy
WHERE location_id = :location_id
ORDER BY closed_at DESC
LIMIT 12;
```

### Merma mensual por causa

```sql
SELECT
    to_char(m.occurred_at, 'YYYY-MM') AS mes,
    CASE
        WHEN m.reference_type = 'portal_event'                    THEN 'hurto_externo'
        WHEN z.kind = 'trastienda'                                THEN 'sospecha_interna'
        WHEN m.movement_type = 'dano'                             THEN 'dano'
        WHEN m.reference_type = 'receiving_order'                 THEN 'error_recepcion'
        WHEN m.reference_type = 'transfer'                        THEN 'error_transferencia'
        ELSE 'desconocida'
    END                                AS causa,
    COUNT(*)                           AS unidades,
    ROUND(SUM(COALESCE(m.unit_cost, pv.cost_price, 0))::numeric, 2) AS costo
FROM stock_movements m
JOIN product_variants pv ON pv.id = m.product_variant_id
LEFT JOIN zones z        ON z.id  = m.from_zone_id
WHERE m.movement_type IN ('merma', 'dano')
  AND m.occurred_at >= date_trunc('month', now() - INTERVAL '12 months')
  AND m.from_location_id = :location_id
GROUP BY 1, 2
ORDER BY 1 DESC, 4 DESC;
```

### Disponibilidad en sala

```sql
WITH por_sku AS (
    SELECT
        t.product_variant_id,
        COUNT(*) FILTER (WHERE z.kind = 'sala')       AS en_sala,
        COUNT(*)                                       AS total
    FROM tags t
    LEFT JOIN zones z ON z.id = t.current_zone_id
    WHERE t.state = 'en_stock'
      AND t.current_location_id = :location_id
    GROUP BY 1
)
SELECT
    COUNT(*)                                          AS sku_con_stock,
    COUNT(*) FILTER (WHERE en_sala > 0)               AS sku_en_sala,
    ROUND(100.0 * COUNT(*) FILTER (WHERE en_sala > 0) / NULLIF(COUNT(*), 0), 2)
        AS disponibilidad_pct
FROM por_sku;
```

### Tasa de sustitución de tags (indicador de calidad del etiquetado)

```sql
SELECT
    to_char(r.performed_at, 'YYYY-MM')                AS mes,
    r.reason,
    COUNT(*)                                          AS sustituciones,
    ROUND(100.0 * COUNT(*) / NULLIF((
        SELECT COUNT(*) FROM tags
        WHERE commissioned_at >= date_trunc('month', r.performed_at)
          AND commissioned_at <  date_trunc('month', r.performed_at) + INTERVAL '1 month'
    ), 0), 3)                                         AS pct_sobre_taradas
FROM tag_replacements r
GROUP BY 1, 2, r.performed_at
ORDER BY 1 DESC;
```

> **Umbral de atención**: por encima del **2 %** mensual de sustituciones, revisar el tipo de etiqueta o su posición en la prenda. Cada sustitución genera una merma falsa en el ciclo siguiente.

---

## 5. Refresco de datos

| Objeto | Frecuencia | Método |
|---|---|---|
| `v_current_stock` y demás vistas | En tiempo real | Vistas normales sobre `tags` |
| `mv_daily_stock` | Diaria 02:00 | `REFRESH MATERIALIZED VIEW CONCURRENTLY` |
| `stock_snapshots` | Diaria 02:15 | Job `RefreshStockSnapshots` |
| Tablero de tienda | Cada 60 s + eventos WebSocket | TanStack Query + Echo |
| Tablero de gerencia | Diaria | Grafana sobre PostgreSQL |

```php
// routes/console.php
Schedule::command('traza:refresh-analytics')->dailyAt('02:00')->onOneServer();
Schedule::command('traza:snapshot-stock')->dailyAt('02:15')->onOneServer();
Schedule::command('traza:check-projection')->dailyAt('03:30')->onOneServer();
Schedule::command('traza:export-cold-reads')->monthlyOn(1, '04:00')->onOneServer();
```

`REFRESH MATERIALIZED VIEW CONCURRENTLY` requiere un índice único (ya definido como `mv_daily_stock_unique`) y no bloquea las lecturas mientras refresca. Sin `CONCURRENTLY`, el tablero de gerencia se quedaría en blanco durante el refresco.

---

## 6. Exportación a frío

Las lecturas crudas de más de 90 días se exportan a Parquet en MinIO antes de purgar la partición:

```php
final class ExportColdReads extends Command
{
    public function handle(): int
    {
        $month = now()->subMonths(4)->format('Y_m');
        $table = "tag_reads_{$month}";

        if (! $this->tableExists($table)) {
            $this->info("No existe la partición {$table}; nada que exportar.");
            return self::SUCCESS;
        }

        // COPY a CSV comprimido; la conversión a Parquet se hace fuera de
        // PostgreSQL con DuckDB, que es mucho más eficiente para esto.
        $path = storage_path("app/cold/{$table}.csv.gz");
        DB::unprepared("COPY (SELECT * FROM {$table}) TO PROGRAM 'gzip > {$path}' WITH CSV HEADER");

        Storage::disk('s3')->putFileAs('cold-reads', new File($path), "{$table}.csv.gz");

        $this->info("Exportado {$table}. Ya puede purgarse la partición.");
        return self::SUCCESS;
    }
}
```

> El orden importa: **exportar primero, purgar después**, y solo si la exportación se verificó. La función `drop_old_tag_reads_partitions()` no debe ejecutarse automáticamente sin comprobar que la exportación del mes correspondiente existe en el almacén de objetos.

---

## 7. Cómo presentar los resultados a la dirección

El error habitual es enseñar exactitud de inventario. A quien firma el cheque no le dice nada.

### Traducción a lenguaje de negocio

| Indicador técnico | Cómo se cuenta |
|---|---|
| Exactitud de 72 % → 98 % | «Antes, uno de cada cuatro registros del sistema estaba mal. Ahora es uno de cada cincuenta.» |
| Ciclo de 32 h → 35 min | «El inventario ya no requiere cerrar la tienda ni pagar horas extra.» |
| Disponibilidad 84 % → 96 % | «De cada 100 modelos que tenemos, antes 16 no estaban en sala. Un cliente que pregunta por ellos se va sin comprar.» |
| Merma clasificada | «Ahora sabemos que el 40 % de lo que perdíamos era error de recepción, no robo. Eso se arregla con el proveedor, no con un guardia.» |

### El cálculo que decide la continuidad

```
Ahorro anual estimado
  = (merma_antes − merma_después) × valor_venta
  + horas_inventario_ahorradas × coste_hora
  + venta_recuperada_por_disponibilidad

Coste anual
  = amortización_hardware + tags_consumidos + horas_de_operación + infraestructura
```

Detallado con cifras en el documento 14, §5. Lo importante metodológicamente: **medir la línea base ANTES de instalar nada**. Si no se hace un inventario manual completo antes de arrancar, no habrá con qué comparar y el retorno será una discusión de opiniones.
