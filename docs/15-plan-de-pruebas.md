# 15 — Plan de pruebas

> En un sistema RFID hay dos clases de defecto: los del software, que se detectan con pruebas automatizadas, y los de radiofrecuencia, que solo aparecen en la tienda con ropa real. Este plan cubre ambas, y trata la segunda con la misma seriedad que la primera.

---

## 1. Pirámide de pruebas

```
                    ╱╲
                   ╱  ╲        Campo (manual, en tienda)      ~15 escenarios
                  ╱────╲       Lo único que valida la física
                 ╱      ╲
                ╱────────╲     E2E (Playwright + simulador)   ~25 flujos
               ╱          ╲
              ╱────────────╲   Integración (Pest + BD real)   ~120 casos
             ╱              ╲
            ╱────────────────╲ Unitarias (Pest, Vitest, JUnit) ~400 casos
           ╱__________________╲
```

---

## 2. Pruebas unitarias

### Backend — lo que nunca puede fallar

| Área | Casos |
|---|---|
| **Codec EPC** | Codificar y decodificar en ambos sentidos para las 7 particiones; rechazo de EPC malformados; serial en el límite (0 y 2³⁸−1); prefijos de longitudes distintas; cálculo del dígito de control del GTIN |
| **Máquina de estados** | Las 100 combinaciones estado×estado; todo tipo de movimiento resuelve a un estado válido; los estados terminales no tienen salida |
| **Máscara EPC** | Coincidencia por prefijo, rechazo de prefijos de prueba en producción, insensibilidad a mayúsculas |
| **Reglas de reconciliación** | Cálculo del umbral de ciclos perdidos, clasificación en las tres poblaciones |
| **Formato** | `formatEpc`, `shortEpc`, formato de importes en PEN |

```php
// tests/Unit/Epc/Sgtin96CodecTest.php
covers(Sgtin96Codec::class);

it('recorre ida y vuelta para todas las particiones', function (int $partition) {
    $codec = new Sgtin96Codec();
    [, $cpDigits, , $irDigits] = Sgtin96Codec::partitionSpec($partition);

    $companyPrefix = str_pad('7', $cpDigits, '5');
    $itemReference = str_pad('0', $irDigits, '3');
    $serial        = 987654321;

    $epc     = $codec->encode($companyPrefix, $itemReference, $serial);
    $decoded = $codec->decode($epc);

    expect(strlen($epc))->toBe(24)
        ->and($decoded['companyPrefix'])->toBe($companyPrefix)
        ->and($decoded['itemReference'])->toBe($itemReference)
        ->and($decoded['serial'])->toBe($serial)
        ->and($decoded['partition'])->toBe($partition);
})->with([0, 1, 2, 3, 4, 5, 6])->group('epc');

it('rechaza un serial que desborda 38 bits', function () {
    expect(fn () => (new Sgtin96Codec())->encode('7751234', '012345', 2 ** 38))
        ->toThrow(InvalidArgumentException::class);
})->group('epc');

it('coincide con el ejemplo de referencia de la documentación', function () {
    expect((new Sgtin96Codec())->encode('7751234', '012345', 1000000042))
        ->toBe('3035D919080C0E403B9ACA2A');
})->group('epc');
```

> El grupo `epc` se ejecuta como una tarea separada en CI (documento 11, §4) para que su fallo sea inconfundible. Un error aquí corrompe identificadores de forma silenciosa e irreversible.

### Pruebas basadas en propiedades

Algunas invariantes se prueban mejor con entradas aleatorias que con casos escogidos:

```php
it('mantiene la invariante de codificación con entradas aleatorias', function () {
    $codec = new Sgtin96Codec();

    foreach (range(1, 500) as $_) {
        $cp     = (string) random_int(1000000, 9999999);      // 7 dígitos
        $ir     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $serial = random_int(0, 274877906943);

        $decoded = $codec->decode($codec->encode($cp, $ir, $serial));

        expect($decoded['companyPrefix'])->toBe($cp)
            ->and($decoded['itemReference'])->toBe($ir)
            ->and($decoded['serial'])->toBe($serial);
    }
})->group('epc');
```

---

## 3. Pruebas de integración

Con PostgreSQL real, nunca con SQLite en memoria: el esquema usa ENUM nativos, particiones, triggers y funciones PL/pgSQL que SQLite no tiene. Probar contra un motor distinto al de producción da falsa confianza.

| Caso | Verifica |
|---|---|
| Movimiento simple | Se escribe en `stock_movements` y se actualiza `tags` en la misma transacción |
| Movimiento con transición ilegal | Lanza excepción y **no** deja rastro parcial |
| `stock_movements` no admite UPDATE | El trigger `forbid_mutation()` dispara |
| Concurrencia | Dos procesos moviendo el mismo tag: uno gana, el otro reintenta, no hay estado inconsistente |
| Reserva de seriales concurrente | 10 procesos reservando rangos: ningún serial se repite |
| Reconciliación de ciclo | Las tres poblaciones cuadran; el umbral de ciclos perdidos se respeta |
| Ingesta idempotente | Reenviar el mismo `batch_id` no duplica filas |
| Máscara EPC | Las lecturas ajenas se rechazan y no llegan a `tag_reads` |
| Particiones | Una lectura con fecha del mes siguiente cae en la partición correcta |
| Proyección vs. movimientos | Tras 1 000 movimientos aleatorios, `stock_as_of()` coincide con `tags` |

```php
it('no pierde ni duplica seriales bajo concurrencia', function () {
    $variant = ProductVariant::factory()->create();

    // 10 procesos reservando 100 seriales cada uno
    $ranges = collect(range(1, 10))->map(fn () =>
        DB::select('SELECT * FROM reserve_serial_range(?, ?)', [$variant->id, 100])[0]
    );

    $todos = $ranges->flatMap(fn ($r) => range($r->serial_from, $r->serial_to));

    expect($todos)->toHaveCount(1000)
        ->and($todos->unique())->toHaveCount(1000)
        ->and($todos->min())->toBe(1)
        ->and($todos->max())->toBe(1000);
});

it('impide modificar un movimiento de stock', function () {
    $movement = StockMovement::factory()->create();

    expect(fn () => DB::table('stock_movements')
        ->where('id', $movement->id)
        ->update(['reason' => 'manipulado']))
        ->toThrow(QueryException::class, 'append-only');
});
```

---

## 4. Pruebas del middleware de borde

Todas usan el `SimulatorAdapter`; ninguna necesita hardware.

| Escenario del simulador | Aserción |
|---|---|
| `inventario_limpio` | ≥ 97 % de la población llega a la API en 60 s |
| `inventario_dificil` | El sistema no marca perdidas prematuramente; `missed_cycles` sube de a uno |
| `vecino_ruidoso` | 0 lecturas ajenas superan la etapa de máscara |
| `portal_salida` | La dirección se clasifica como `salida` con confianza ≥ 0.7 |
| `portal_dudoso` | **No** se emite evento de salida |
| `red_caida` | Nada se pierde: buffer crece, y al restaurar la red se vacía completo |
| `avalancha` | 8 000 lecturas/s durante 60 s sin que la memoria supere 512 MB |
| Reinicio a mitad de escaneo | Las lecturas en vuelo sobreviven en SQLite |

```typescript
describe('resiliencia ante caída de red', () => {
  it('no pierde ninguna lectura durante 10 minutos sin API', async () => {
    const api = new FakeApiClient({ failFor: 600_000 });
    const buffer = new SqliteBuffer(':memory:');
    const edge = createEdge({ api, buffer, scenario: 'red_caida' });

    await edge.start();
    await advanceTime(600_000);

    const emitidas = edge.pipeline.snapshot().passed;
    expect(buffer.depth()).toBe(emitidas);

    api.restore();
    await advanceTime(120_000);

    expect(buffer.depth()).toBe(0);
    expect(api.receivedCount()).toBe(emitidas);
  });
});
```

---

## 5. Pruebas de la app de mano

| Nivel | Cobertura |
|---|---|
| Unitarias | Deduplicación local, resolución de conflictos de tarado, cálculo de progreso |
| Con `FakeRfidReader` | Los 5 modos completos, sin hardware |
| Instrumentadas | Navegación, persistencia de ciclo tras cierre de la app |
| Manuales | Ergonomía, batería, gatillo, legibilidad bajo luz real |

```kotlin
@Test
fun `un ciclo sobrevive al cierre de la aplicacion`() = runTest {
    val vm = InventoryViewModel(FakeRfidReader(population(5000)), scanRepo, scheduler)
    vm.startCycle(cycleId = 42, zoneId = 1)
    advanceTimeBy(10_000)

    val antes = vm.state.value.scannedCount
    assertTrue(antes > 0)

    // Simula muerte del proceso: nueva instancia, mismo repositorio
    val vm2 = InventoryViewModel(FakeRfidReader(emptyList()), scanRepo, scheduler)
    vm2.resumeCycle(cycleId = 42)

    assertEquals(antes, vm2.state.value.scannedCount)
}
```

---

## 6. Pruebas E2E

Con Playwright contra el entorno completo de Compose, usando el simulador.

| Flujo | Pasos |
|---|---|
| Alta de producto → tarado → ciclo → conciliación | El recorrido completo del sistema |
| Recepción con diferencia | La orden queda con faltante documentado |
| Venta y cruce de portal dentro del periodo de gracia | **No** suena la alarma |
| Cruce de portal sin venta | Se genera alerta `salida_no_vendida` |
| Prenda faltante en 2 ciclos consecutivos | Pasa a `perdido` en el segundo, no en el primero |
| Prenda faltante que reaparece | Vuelve a `en_stock` y genera alerta de reaparición |
| Transferencia entre tiendas | Estados y stock correctos en origen y destino |
| Devolución de cliente | La prenda vuelve al stock con trazabilidad intacta |

```typescript
test('una prenda no se declara perdida en el primer ciclo', async ({ page, api }) => {
  const { epc } = await api.commissionTag({ sku: 'TEST-001', location: 'LIM-01' });

  // Ciclo 1: el tag no se escanea
  await api.runCycle({ location: 'LIM-01', exclude: [epc] });
  await page.goto(`/prendas/${epc}`);
  await expect(page.getByTestId('tag-state')).toHaveText('No visto');

  // Ciclo 2: sigue sin escanearse
  await api.runCycle({ location: 'LIM-01', exclude: [epc] });
  await page.reload();
  await expect(page.getByTestId('tag-state')).toHaveText('Perdido');
});
```

---

## 7. Pruebas de carga

Con k6 contra el entorno de staging.

| Prueba | Objetivo | Criterio |
|---|---|---|
| Ingesta sostenida | 5 000 lecturas/s durante 10 min | p95 < 300 ms; 0 errores; sin crecimiento de cola |
| Pico de ingesta | 15 000 lecturas/s durante 60 s | Degradación elegante; nada se pierde |
| Cierre de ciclo grande | Reconciliar 50 000 tags | < 60 s |
| Latencia de portal | 100 eventos con lectura de ventas concurrente | p99 < 800 ms extremo a extremo |
| Consulta de stock | 200 usuarios concurrentes | p95 < 200 ms |

```javascript
// tests/load/ingest.js
import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    ingesta: {
      executor: 'constant-arrival-rate',
      rate: 10,              // 10 lotes/s × 500 lecturas = 5 000 lecturas/s
      timeUnit: '1s',
      duration: '10m',
      preAllocatedVUs: 20,
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<300'],
    http_req_failed:   ['rate<0.001'],
  },
};

export default function () {
  const reads = Array.from({ length: 500 }, () => ({
    epc: randomEpc('3035D9'),
    rssi: -50 - Math.random() * 25,
    antenna: 1,
    read_at: new Date().toISOString(),
    read_count: 1,
  }));

  const res = http.post(`${__ENV.API_URL}/api/v1/ingest/reads`,
    JSON.stringify({ device_code: 'EDGE-LOAD', batch_id: uuid(), reads }),
    { headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${__ENV.TOKEN}` } });

  check(res, { 'aceptado': (r) => r.status === 202 });
}
```

---

## 8. Pruebas de campo (RF)

**Ninguna prueba automatizada valida esto.** Requiere ropa real, tienda real y personas reales.

### PC01 — Tasa de lectura por material

| Aspecto | Detalle |
|---|---|
| Preparación | 100 prendas del peor caso: jeans con remaches, ropa de baño, prendas con hilo metálico, tejidos gruesos |
| Ejecución | 3 pasadas de 15 s a potencia media, en pila y colgadas |
| Criterio | ≥ 95 % en una pasada; ≥ 99 % en tres |
| Frecuencia | Fase 0, y ante todo cambio de proveedor de tags o de surtido |

### PC02 — Cobertura del ciclo completo

| Aspecto | Detalle |
|---|---|
| Preparación | Marcar 50 prendas testigo repartidas por toda la tienda, incluidos los rincones difíciles: fondo de estante, dentro de cajón, bajo mostrador |
| Ejecución | Ciclo normal por un operario formado |
| Criterio | Las 50 testigo detectadas. **Cada testigo no detectada identifica un punto ciego físico** |
| Frecuencia | Al arrancar la tienda, y trimestralmente |

### PC03 — Calibración del portal

Protocolo completo en el documento 03, §4. Resumen:

| Criterio | Umbral |
|---|---|
| Detección en cruce a paso normal | ≥ 99 % |
| Lecturas de tags estáticos a más de 2.5 m | 0 |
| Clasificación correcta de dirección | ≥ 95 % |
| Falsos positivos en 200 tránsitos legítimos | ≤ 5 |

### PC04 — Interferencia con el local vecino

| Aspecto | Detalle |
|---|---|
| Preparación | 30 tags marcados, colocados en el local contiguo o al otro lado del tabique |
| Ejecución | Ciclo completo en la tienda propia |
| Criterio | 0 de los 30 aparecen en el ciclo |
| Si fallan | Bajar potencia, revisar la máscara EPC, subir el umbral de RSSI |

### PC05 — Autonomía y ergonomía

| Aspecto | Criterio |
|---|---|
| Batería | Un ciclo completo de 20 000 unidades con ≥ 30 % restante |
| Fatiga | El operario completa el ciclo sin pausa forzada por peso |
| Legibilidad | El contador se lee a 40 cm bajo la luz real de la tienda |
| Sonido | Los pitidos se distinguen con música ambiental de tienda |

### PC06 — Operación degradada

| Escenario | Comportamiento esperado |
|---|---|
| Corte de internet a mitad de ciclo | El handheld sigue contando; sincroniza al volver |
| Corte de luz | El UPS mantiene el borde; la tienda vende por código de barras |
| Handheld sin batería a mitad de ciclo | Cambio de batería; el ciclo se reanuda donde estaba |
| Lector de portal desconectado | Alerta al equipo técnico; las ventas continúan |
| API caída | El handheld encola; la web muestra el fallo sin bloquear |

---

## 9. Pruebas de aceptación del usuario

Realizadas por personal de tienda, no por el equipo de desarrollo.

| Prueba | Quién | Criterio |
|---|---|---|
| Tarar 50 prendas | Operario de almacén con 30 min de formación | Sin errores, ≤ 6 s por prenda |
| Ciclo completo | Vendedor con 30 min de formación | Sin ayuda del equipo técnico |
| Localizar 5 prendas concretas | Vendedor | ≤ 3 min cada una |
| Interpretar el informe de conciliación | Jefe de tienda | Identifica correctamente qué investigar |
| Gestionar una alarma de portal | Vendedor | Sigue el protocolo P09 sin acusar a nadie |

> **La regla de oro de la aceptación**: si el equipo de desarrollo tiene que estar presente para que funcione, no está aceptado.

---

## 10. Criterios de entrada a producción

```
Automatizadas
[ ] Cobertura de pruebas ≥ 70 % en backend
[ ] Grupo 'epc' pasando al 100 %
[ ] Suite de integración pasando contra PostgreSQL 16
[ ] E2E pasando con el simulador
[ ] Pruebas de carga cumpliendo los umbrales de §7

Campo
[ ] PC01 superada con el inlay elegido
[ ] PC02 superada en la tienda piloto
[ ] PC03 superada si hay portal instalado
[ ] PC04 superada (crítica en Gamarra)
[ ] PC05 superada
[ ] PC06 superada en los 5 escenarios

Aceptación
[ ] Las 5 pruebas de §9 superadas por personal de tienda
[ ] Línea base de exactitud medida antes de arrancar

Operación
[ ] Lista de verificación de seguridad completa (documento 12, §8)
[ ] Respaldo restaurado con éxito al menos una vez
[ ] Alertas de Prometheus probadas provocando cada condición
[ ] Manual de incidencias probado por alguien que no lo escribió
[ ] Responsable técnico de guardia designado
```

> El penúltimo punto es el que más se salta y el que más duele: **un manual de incidencias que solo entiende su autor no sirve para nada a las 22:00 de un sábado.**
