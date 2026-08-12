# 04 — Codificación EPC y política de numeración

> El esquema de numeración es la decisión más difícil de revertir del proyecto. Cambiarlo después de etiquetar 50 000 prendas significa re-etiquetar 50 000 prendas. Este documento debe aprobarse formalmente antes de codificar el primer tag.

---

## 1. El concepto que hay que interiorizar

| | Código de barras EAN-13 | EPC en RFID |
|---|---|---|
| Identifica | El **modelo** (SKU) | La **unidad física individual** |
| Dos poleras rojas talla M | Mismo código | **EPC distintos** |
| Analogía | Tipo de auto | Número de chasis |

Esto cambia el modelo de datos por completo. En un sistema de código de barras, el stock es un número (`polera_roja_M: 14`). En RFID, el stock es un **conjunto de identidades** (`{EPC-a1, EPC-b7, EPC-c2, ...}`), y el número 14 es un `COUNT(*)` derivado.

Consecuencia práctica: puedes responder preguntas imposibles con código de barras:

- ¿Cuánto tiempo lleva **esta** prenda concreta sin venderse?
- La prenda devuelta, ¿es la misma que se vendió el martes?
- De las 14 poleras, ¿cuáles están en sala y cuáles en almacén?
- ¿Qué prendas concretas desaparecieron entre el ciclo 42 y el 43?

---

## 2. Opciones de esquema

### 2.1 SGTIN-96 — el estándar (recomendado)

**Serialized Global Trade Item Number**, header `0x30`. Es el esquema que usa todo el retail mundial. Requiere un **prefijo de compañía GS1**.

#### Estructura de 96 bits

```
 ┌────────┬────────┬─────────┬──────────────────┬──────────────┬──────────────┐
 │ Header │ Filter │Partition│ Company Prefix   │ Item Ref     │ Serial       │
 │ 8 bits │ 3 bits │ 3 bits  │ 20–40 bits       │ 4–24 bits    │ 38 bits      │
 │  0x30  │        │         │                  │              │              │
 └────────┴────────┴─────────┴──────────────────┴──────────────┴──────────────┘
                              └── Company Prefix + Item Ref = 44 bits ──┘
```

#### Tabla de particiones

| Partition | Bits prefijo compañía | Dígitos prefijo | Bits item ref | Dígitos item ref |
|---:|---:|---:|---:|---:|
| 0 | 40 | 12 | 4 | 1 |
| 1 | 37 | 11 | 7 | 2 |
| 2 | 34 | 10 | 10 | 3 |
| 3 | 30 | 9 | 14 | 4 |
| 4 | 27 | 8 | 17 | 5 |
| 5 | 24 | 7 | 20 | 6 |
| 6 | 20 | 6 | 24 | 7 |

> Cuanto más corto tu prefijo de compañía, más dígitos te quedan para productos. Un prefijo de 7 dígitos (partición 5) deja **6 dígitos de referencia de artículo** = hasta 999 999 SKU. Más que suficiente.

#### Valores de Filter

| Valor | Significado | Uso |
|---:|---|---|
| 0 | Otros | — |
| 1 | **Unidad de consumo (POS trade item)** | ← **Usar este para prendas individuales** |
| 2 | Caja completa | Bultos de recepción |
| 4 | Inner pack | Packs |
| 6 | Unidad de carga (pallet) | Logística |

El filtro permite que un lector de portal ignore por hardware todo lo que no sea `filter=1`, sin procesarlo en software. Es un ahorro real de rendimiento.

#### Serial

38 bits → **274 877 906 943** seriales por combinación de prefijo + referencia. Es un espacio prácticamente inagotable.

> **Política TRAZA**: el serial **no se reutiliza jamás**, ni siquiera tras dar de baja una prenda. Un serial reutilizado destruye la trazabilidad histórica y produce falsos positivos de "reaparición".

#### Ejemplo trabajado completo

Supongamos:

| Campo | Valor |
|---|---|
| Prefijo de compañía GS1 (Perú, prefijo país 775) | `7751234` (7 dígitos → partición 5) |
| Dígito indicador | `0` (unidad de consumo) |
| Referencia de artículo | `12345` |
| GTIN-13 resultante | `7751234123456` (el `6` final es dígito de control) |
| Serial | `1000000042` |

Cálculo:

```
Header       = 0x30                       → 0011 0000
Filter       = 1                          → 001
Partition    = 5                          → 101
Company Pfx  = 7751234   (24 bits)        → 0111 0110 0100 0110 0100 0010
Item Ref     = 012345    (20 bits)        → 0000 0011 0000 0011 1001
Serial       = 1000000042 (38 bits)       → 00 0000 0011 1011 1001 1010 1100 1010 0010 1010
```

**EPC resultante (hex, 24 caracteres):**

```
3035D919080C0E403B9ACA2A
```

Este es el valor exacto que se escribe en el banco EPC del tag y el que viaja por todo el sistema.

---

### 2.2 GID-96 — sin GS1 (alternativa de arranque)

**General Identifier**, header `0x35`. No requiere GS1, pero **no es interoperable**: ningún socio comercial podrá interpretar tus EPC.

```
┌────────┬───────────────────────┬──────────────────┬───────────────┐
│ Header │ General Manager Number│ Object Class     │ Serial        │
│ 8 bits │ 28 bits               │ 24 bits          │ 36 bits       │
│  0x35  │                       │                  │               │
└────────┴───────────────────────┴──────────────────┴───────────────┘
```

- **General Manager Number**: debería asignarlo GS1. Si no lo tienes, se elige un valor arbitrario, asumiendo el riesgo de colisión con otra empresa (bajo, pero real).
- **Object Class**: tu SKU interno (hasta 16 777 215 productos).
- **Serial**: 36 bits = 68 719 476 735 unidades por SKU.

---

### 2.3 Decisión de TRAZA

> **ADR-009 — Esquema de codificación**
>
> **Decisión**: usar **SGTIN-96** si la empresa ya tiene o puede obtener un prefijo de compañía GS1 Perú. Si no, arrancar con **GID-96** en Fase 0/1, con migración planificada a SGTIN en Fase 2.
>
> **Justificación**: SGTIN es el único camino a interoperar con proveedores, marketplaces y clientes corporativos. El coste de la afiliación a GS1 Perú es modesto comparado con el coste de re-etiquetar el inventario completo más adelante.
>
> **Consecuencia**: si se arranca con GID-96, el sistema debe soportar **ambos esquemas simultáneamente** desde el diseño. La tabla `tags` almacena `epc_scheme` y los campos decodificados; el codificador es una estrategia intercambiable.

> ⚠️ **VERIFICAR**: costos y requisitos de afiliación a **GS1 Perú**, y plazo de asignación de prefijo. Consultar directamente con la organización antes de fijar el cronograma de Fase 1.

---

## 3. Política de numeración

### 3.1 Reglas

| Regla | Detalle |
|---|---|
| **R1 — Serial monotónico por SKU** | El serial se asigna desde un contador por `product_variant_id`, no global. Facilita depuración y particionado |
| **R2 — Sin reutilización** | Ningún serial vuelve a emitirse. El contador solo sube |
| **R3 — Reserva antes de imprimir** | El sistema reserva un rango de seriales *antes* de mandar imprimir. Si la impresión falla, el rango se marca `anulado`, no se libera |
| **R4 — Lotes de impresión trazables** | Cada trabajo de impresión genera un `tag_batch` con rango, usuario, hora, y estado por unidad |
| **R5 — Prefijo de prueba separado** | Los entornos `local` y `staging` usan un **prefijo de compañía o Object Class reservado** que producción rechaza. Evita que un tag de prueba contamine el inventario real |
| **R6 — Máscara de filtro** | El middleware descarta toda lectura cuyo EPC no case con la máscara de la organización. Es la primera línea contra lecturas de terceros |

### 3.2 Asignación concurrente de seriales

El contador es un punto de contención. Implementación:

```sql
-- Reserva atómica de un rango de N seriales para una variante
CREATE OR REPLACE FUNCTION reserve_serial_range(
    p_variant_id BIGINT,
    p_count      INT
) RETURNS TABLE(serial_from BIGINT, serial_to BIGINT) AS $$
DECLARE
    v_current BIGINT;
BEGIN
    -- UPDATE ... RETURNING sobre la fila del contador toma un lock de fila,
    -- serializa a los productores y devuelve el rango en una sola ida y vuelta.
    UPDATE product_variant_counters
       SET last_serial = last_serial + p_count,
           updated_at  = now()
     WHERE product_variant_id = p_variant_id
    RETURNING last_serial - p_count + 1, last_serial
      INTO serial_from, serial_to;

    IF NOT FOUND THEN
        INSERT INTO product_variant_counters (product_variant_id, last_serial)
        VALUES (p_variant_id, p_count)
        RETURNING 1::BIGINT, last_serial INTO serial_from, serial_to;
    END IF;

    RETURN NEXT;
END;
$$ LANGUAGE plpgsql;
```

> **Nota**: no usar `SEQUENCE` de PostgreSQL por variante — se necesitarían miles de secuencias. El patrón de contador en tabla con `UPDATE ... RETURNING` es correcto y suficientemente rápido (el cuello de botella real es la impresora, a ~10 tags/s).

---

## 4. Implementación del codificador (PHP)

```php
<?php

declare(strict_types=1);

namespace App\Domain\Tagging\Epc;

use InvalidArgumentException;

/**
 * Codificador / decodificador SGTIN-96 (EPCglobal Tag Data Standard).
 *
 * Trabaja con GMP para manejar enteros de 96 bits sin pérdida de precisión.
 * PHP no tiene enteros de 96 bits nativos; usar int provocaría corrupción
 * silenciosa de EPC, que es el peor fallo posible en este sistema.
 */
final class Sgtin96Codec
{
    private const HEADER = 0x30;

    /** partition => [bits prefijo, dígitos prefijo, bits itemRef, dígitos itemRef] */
    private const PARTITIONS = [
        0 => [40, 12,  4, 1],
        1 => [37, 11,  7, 2],
        2 => [34, 10, 10, 3],
        3 => [30,  9, 14, 4],
        4 => [27,  8, 17, 5],
        5 => [24,  7, 20, 6],
        6 => [20,  6, 24, 7],
    ];

    private const SERIAL_BITS = 38;
    private const SERIAL_MAX  = 274877906943; // 2^38 - 1

    public function encode(
        string $companyPrefix,   // solo dígitos, p.ej. "7751234"
        string $itemReference,   // indicador + referencia, p.ej. "012345"
        int    $serial,
        int    $filter = 1
    ): string {
        $partition = $this->partitionForPrefixLength(strlen($companyPrefix));
        [$cpBits, $cpDigits, $irBits, $irDigits] = self::PARTITIONS[$partition];

        if (strlen($itemReference) !== $irDigits) {
            throw new InvalidArgumentException(
                "La referencia de artículo debe tener {$irDigits} dígitos para la partición {$partition}."
            );
        }
        if ($serial < 0 || $serial > self::SERIAL_MAX) {
            throw new InvalidArgumentException('Serial fuera del rango de 38 bits.');
        }
        if ($filter < 0 || $filter > 7) {
            throw new InvalidArgumentException('Filter debe estar entre 0 y 7.');
        }

        $v = gmp_init(0);
        $v = gmp_or(gmp_mul($v, gmp_pow(2, 8)),  gmp_init(self::HEADER));
        $v = gmp_or(gmp_mul($v, gmp_pow(2, 3)),  gmp_init($filter));
        $v = gmp_or(gmp_mul($v, gmp_pow(2, 3)),  gmp_init($partition));
        $v = gmp_or(gmp_mul($v, gmp_pow(2, $cpBits)), gmp_init($companyPrefix, 10));
        $v = gmp_or(gmp_mul($v, gmp_pow(2, $irBits)), gmp_init($itemReference, 10));
        $v = gmp_or(gmp_mul($v, gmp_pow(2, self::SERIAL_BITS)), gmp_init($serial));

        return strtoupper(str_pad(gmp_strval($v, 16), 24, '0', STR_PAD_LEFT));
    }

    /** @return array{scheme:string,filter:int,partition:int,companyPrefix:string,itemReference:string,serial:int,gtin13:string} */
    public function decode(string $epcHex): array
    {
        $epcHex = strtoupper(trim($epcHex));
        if (!preg_match('/^[0-9A-F]{24}$/', $epcHex)) {
            throw new InvalidArgumentException('Un EPC SGTIN-96 debe tener 24 caracteres hexadecimales.');
        }

        $v      = gmp_init($epcHex, 16);
        $header = (int) gmp_strval($this->slice($v, 88, 8));
        if ($header !== self::HEADER) {
            throw new InvalidArgumentException(sprintf('Header 0x%02X no es SGTIN-96.', $header));
        }

        $filter    = (int) gmp_strval($this->slice($v, 85, 3));
        $partition = (int) gmp_strval($this->slice($v, 82, 3));

        if (!isset(self::PARTITIONS[$partition])) {
            throw new InvalidArgumentException("Partición inválida: {$partition}.");
        }
        [$cpBits, $cpDigits, $irBits, $irDigits] = self::PARTITIONS[$partition];

        $cp     = str_pad(gmp_strval($this->slice($v, 58 + (24 - $cpBits), $cpBits)), $cpDigits, '0', STR_PAD_LEFT);
        $ir     = str_pad(gmp_strval($this->slice($v, self::SERIAL_BITS, $irBits)),   $irDigits, '0', STR_PAD_LEFT);
        $serial = (int) gmp_strval(gmp_and($v, gmp_sub(gmp_pow(2, self::SERIAL_BITS), 1)));

        return [
            'scheme'        => 'sgtin-96',
            'filter'        => $filter,
            'partition'     => $partition,
            'companyPrefix' => $cp,
            'itemReference' => $ir,
            'serial'        => $serial,
            'gtin13'        => $this->toGtin13($cp, $ir),
        ];
    }

    private function slice(\GMP $v, int $shift, int $bits): \GMP
    {
        return gmp_and(gmp_div_q($v, gmp_pow(2, $shift)), gmp_sub(gmp_pow(2, $bits), 1));
    }

    private function partitionForPrefixLength(int $len): int
    {
        foreach (self::PARTITIONS as $p => [$b, $digits]) {
            if ($digits === $len) {
                return $p;
            }
        }
        throw new InvalidArgumentException("No hay partición para un prefijo de {$len} dígitos.");
    }

    /** Reconstruye el GTIN-13: el primer dígito de itemReference es el indicador. */
    private function toGtin13(string $cp, string $ir): string
    {
        $base = $cp . substr($ir, 1);            // se descarta el indicador
        return $base . $this->checkDigit($base);
    }

    private function checkDigit(string $digits): string
    {
        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 3 : 1);
        }
        return (string) ((10 - $sum % 10) % 10);
    }
}
```

### Prueba de aceptación del codificador

```php
it('codifica el EPC de referencia del documento 04', function () {
    $codec = new Sgtin96Codec();

    $epc = $codec->encode(
        companyPrefix: '7751234',
        itemReference: '012345',
        serial: 1000000042,
        filter: 1,
    );

    expect($epc)->toBe('3035D919080C0E403B9ACA2A');

    $decoded = $codec->decode($epc);
    expect($decoded['companyPrefix'])->toBe('7751234')
        ->and($decoded['itemReference'])->toBe('012345')
        ->and($decoded['serial'])->toBe(1000000042)
        ->and($decoded['partition'])->toBe(5)
        ->and($decoded['gtin13'])->toBe('7751234123456');
});
```

> Este test es **obligatorio en CI**. Un bug en el codificador no se detecta a simple vista y corrompe el inventario de forma silenciosa e irreversible.

---

## 5. Escritura en el tag: ZPL

La impresora Zebra codifica y escribe el EPC durante la impresión. Comandos relevantes:

| Comando | Función |
|---|---|
| `^RS` | Configura el tipo de tag y el comportamiento ante error |
| `^RFW,H` | Escribe en hexadecimal en el banco indicado |
| `^RFR,H` | Lee del tag (para verificación) |
| `^RB` | Define la estructura de bits del bloque |
| `^RZ` | Establece la contraseña de acceso |
| `^WV` | Habilita verificación de escritura |
| `~RO` | Reinicia contadores de RFID |

### Plantilla ZPL de etiqueta colgante

```zpl
^XA
^RS8,,,3,N              ; tipo Gen2, 3 reintentos, sin protocolo de error especial
^RFW,H,1,12,1           ; escribir banco EPC (1), 12 bytes, offset 1 palabra
^FD3035D919080C0E403B9ACA2A^FS
^WV,Y                   ; verificar tras escribir

^FO30,30^A0N,28,28^FDPOLERA OVERSIZE^FS
^FO30,70^A0N,24,24^FDTalla: M   Color: Negro^FS
^FO30,110^A0N,40,40^FDS/ 89.90^FS
^FO30,170^BY2^BCN,80,Y,N,N^FD7751234123456^FS   ; EAN-13 impreso (compatibilidad)
^FO420,30^A0N,20,20^FDSKU 12345^FS

^XZ
```

### Manejo de tags defectuosos

Un rollo de inlays tiene una tasa de fallo típica del **0.1 % – 1 %**. La impresora Zebra detecta el fallo de escritura y, configurada correctamente, imprime **"VOID"** sobre la etiqueta y reintenta con la siguiente.

Configuración recomendada:

```zpl
^XA
^RS8,,,3,N       ; 3 reintentos
^RFW,H,1,12,1
^RQ              ; consulta el resultado
^XZ
```

**Regla de proceso**: el operario **debe** descartar físicamente toda etiqueta marcada VOID. El sistema registra cada fallo en `tag_print_failures` para reclamar al proveedor de inlays si la tasa supera el 1 %.

---

## 6. Seguridad del tag: bloqueo y kill

### 6.1 Access password y bloqueo de memoria

Tras escribir el EPC, se recomienda:

1. Escribir un **Access Password** de 32 bits en el banco Reserved.
2. Ejecutar `Lock` sobre el banco EPC en modo *write-locked* (escribible solo conociendo la contraseña).

Esto impide que un tercero reescriba el EPC de tus prendas con un lector comercial. No impide **leer** el EPC, que por diseño es público.

> **Política TRAZA**: el Access Password se **deriva** del EPC mediante HMAC con una clave maestra guardada en el servidor, no se almacena por tag. Así se puede recalcular sin mantener una tabla de secretos:
>
> ```
> access_password = primeros_32_bits( HMAC-SHA256( clave_maestra, epc ) )
> ```
>
> La clave maestra vive en el gestor de secretos, nunca en el repositorio ni en el handheld. El handheld solicita la contraseña al servidor cuando necesita escribir.

### 6.2 Kill password

El comando `Kill` desactiva el tag de forma **permanente e irreversible**.

> **Decisión TRAZA: NO se hace kill de tags en la venta.**
>
> Razones:
> 1. Un tag vivo permite validar devoluciones (¿esta prenda es realmente la que vendí?).
> 2. Permite trazabilidad post-venta si se implementa garantía o programa de fidelización.
> 3. Es irreversible: un kill erróneo destruye el tag.
>
> **A cambio, se asume el debate de privacidad**: un tag vivo puede leerse fuera de la tienda. Se mitiga (a) usando hangtag retirable en caja, no etiqueta cosida, y (b) informando al cliente. Ver documento 12.

---

## 7. Casos límite y su tratamiento

| Caso | Tratamiento |
|---|---|
| **Tag ilegible en tienda** | Estado `sin_tag`: la prenda existe pero no es rastreable. Se re-etiqueta con un EPC nuevo, registrando la sustitución (`tag_replacements`) para no contarla dos veces |
| **EPC duplicado leído** | Dos tags con el mismo EPC = clonación o error de impresión. Alerta crítica. Se comparan los TID |
| **EPC desconocido en el portal** | Puede ser (a) mercadería de otra tienda, (b) tag ajeno, (c) prenda no tarada. Se registra en `unknown_epcs` para investigación, **sin** disparar alarma por defecto |
| **Prenda sin tag detectada en caja** | El vendedor la registra por código de barras; el sistema crea un movimiento de venta sin EPC y marca el SKU para reconciliación |
| **Tag arrancado y abandonado en tienda** | Se leerá en cada ciclo indefinidamente. Detección: EPC que aparece siempre en la misma posición pero cuya prenda nunca se vende. Se resuelve con una lista de "tags sospechosos" revisada mensualmente |
| **Cambio de precio** | No requiere re-etiquetar RFID (el precio no está en el tag). Solo se reimprime el hangtag si el precio está impreso, reutilizando el mismo EPC si el tag es legible |
| **Prenda transformada (arreglo, corte)** | Nuevo SKU → nuevo EPC. El anterior pasa a `baja` con motivo `transformacion`, enlazando ambos por `parent_tag_id` |

---

## 8. Migración desde código de barras

TRAZA convive con el sistema de códigos de barras existente durante toda la transición.

```
   Producto (modelo)
        │
        ├── EAN-13 / código interno  ──▶ usado en caja, catálogos, proveedores
        │
        └── ProductVariant (SKU: talla + color)
                 │
                 ├── GTIN-13 propio     ──▶ base del SGTIN
                 │
                 └── N × Tag (EPC)      ──▶ una unidad física cada uno
```

**Regla de convivencia**: toda operación posible por EPC debe seguir siendo posible por SKU. Si el sistema *exige* RFID para vender, una caída del lector cierra la tienda. El RFID **enriquece**, no bloquea.
