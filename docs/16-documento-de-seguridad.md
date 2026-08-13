# 16 — Documento de seguridad

Complemento operativo de `docs/12-seguridad-y-privacidad.md`. Recoge lo que
está implementado, lo que falta y quién responde de cada cosa.

> ⚠️ **Este documento no sustituye a la asesoría legal.** La Ley 29733 y su
> reglamento fijan obligaciones concretas sobre el tratamiento de datos
> personales cuya interpretación corresponde a un abogado. La tarea 9.4 sigue
> abierta hasta que esa consulta se haya hecho y esté documentada aquí.

---

## 1. Qué protege este sistema

| Activo | Por qué importa |
|---|---|
| Inventario valorizado | Es el dinero de la empresa en forma de prendas |
| Histórico de movimientos | Fuente de verdad append-only; su integridad sostiene toda la contabilidad de stock |
| Clave maestra de access password | Su pérdida deja todos los tags bloqueados sin poder reescribirse jamás |
| Registro de acciones de empleados | Dato personal sujeto a la Ley 29733 |
| Tokens de dispositivo | Permiten inyectar lecturas falsas en el inventario |

**No** se almacenan datos de clientes finales. El sistema identifica prendas,
no personas. Es la decisión de diseño que mantiene la exposición baja.

---

## 2. Control de acceso implementado

Los 7 roles de `docs/12` §2 están en `App\Enums\RoleCode`, con sus permisos y
una prueba por rol para cada operación sensible.

La separación crítica está verificada con pruebas:

- `tecnico` puede cambiar el perfil de un lector pero **no** ajustar stock.
- `jefe_tienda` puede declarar merma pero **no** tocar el perfil de lectura.

Sin esa separación, un mismo actor podría bajar la potencia de un lector,
provocar que no se lea una zona, y luego justificar como merma la mercadería
que él mismo se llevó.

### Doble aprobación

| Operación | Umbral | Implementado en |
|---|---|---|
| Ajuste manual | > 20 unidades | `StockAdjustmentPolicy::apply()` |
| Merma | > S/ 2 000 | `StockAdjustmentPolicy::apply()` |
| Anular lote tarado | siempre | `StockAdjustmentPolicy::voidTagBatch()` |
| Cambiar máscara EPC | siempre, con motivo escrito | `StockAdjustmentPolicy::changeEpcMask()` |
| Cerrar ciclo con exactitud < 90 % | siempre, con justificación | `InventoryCyclePolicy::close()` |

El último no es burocracia: un ciclo con exactitud baja casi siempre significa
que faltó barrer una zona. Cerrarlo genera merma falsa y destruye la confianza
en las cifras del sistema.

---

## 3. Trazabilidad

`AuditObserver` registra creación, modificación y borrado de los modelos
sensibles con usuario, dispositivo, IP y agente. Las contraseñas y los hashes
de token se omiten del registro.

`stock_movements` no lleva observador porque **es** su propia auditoría: el
trigger `forbid_mutation()` de PostgreSQL rechaza UPDATE y DELETE, así que el
histórico no se puede reescribir ni desde una consola SQL.

---

## 4. Secretos

Ver la tabla completa en `docs/12` §3. Estado actual:

| Secreto | Estado |
|---|---|
| `APP_KEY` | En variable de entorno; nunca versionado |
| `TRAZA_TAG_ACCESS_MASTER_KEY` | Derivación implementada; **la custodia es responsabilidad de operaciones** |
| Token de dispositivo | Hasheado en `devices.api_token_hash`; el valor claro solo existe al crearlo |
| Contraseña de PostgreSQL | En `.env`, fuera del repositorio |

La clave maestra se custodia como una clave de firma: **copia sellada fuera de
línea, en dos ubicaciones físicas distintas**. Si se pierde, ningún tag ya
bloqueado podrá reescribirse nunca más.

---

## 5. Estado de la lista de verificación previa a producción

De la lista de `docs/12` §8:

### Infraestructura

| Punto | Estado |
|---|---|
| TLS 1.3 y HSTS | HSTS enviado bajo TLS; **el certificado y el proxy son de despliegue** |
| Base de datos sin puerto público | Hecho: el compose de producción solo publica 80, 443 y 8883 |
| MQTT por 8883 con TLS y ACL | Hecho: `mosquitto.prod.conf` sin listener en claro, y `acl` por tienda |
| WireGuard en los bordes | **Pendiente** — tarea 9.5 |
| Respaldos verificados | **Pendiente** — tarea 8.6 |
| Secretos fuera del repositorio | Hecho; `.gitignore` cubre `.env`, y se ha verificado en cada entrega |

### Aplicación

| Punto | Estado |
|---|---|
| `APP_DEBUG=false` en producción | El `.env.example` de producción lo fija; verificar en el despliegue real |
| Límites de tasa | Hecho: 300/min por usuario, 2000/min por dispositivo |
| Políticas con pruebas por rol | Hecho: 36 pruebas en `tests/Feature/SecurityTest.php` |
| Cabeceras de seguridad | Hecho: CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy |
| Auditoría en modelos sensibles | Hecho |
| Dependencias sin vulnerabilidades | **Pendiente**: añadir `composer audit` y `npm audit` a CI — tarea 0.4 |

### RFID

| Punto | Estado |
|---|---|
| Access password derivado | Hecho, con endpoint por EPC |
| Kill password aleatorio por tag | Hecho, derivado y distinto del de acceso |
| Máscara EPC verificada con lecturas reales | **Pendiente**: exige hardware — tarea 0.1 |
| Prefijo de pruebas rechazado en producción | Hecho, en `EpcMask` y en el pipeline del borde |

### Cumplimiento

**Todo pendiente y todo fuera del alcance del código**: cartel informativo al
cliente, información a los empleados sobre el registro de sus acciones,
asesoría legal sobre la Ley 29733, homologación MTC de los equipos y
procedimiento de respuesta a incidentes con responsable asignado.

---

## 6. Lo que este documento NO cubre todavía

1. **Asesoría legal sobre la Ley 29733.** Sin ella, la tarea 9.4 no se puede
   cerrar. Es una consulta externa, no una tarea de desarrollo.
2. **Procedimiento de respuesta a incidentes**, con responsable con nombre y
   apellidos y un teléfono al que llamar.
3. **Homologación MTC** de los lectores, que condiciona qué hardware es legal
   operar en Perú (ver `docs/01` §3).
