# 12 — Seguridad y privacidad

---

## 1. Modelo de amenazas

### 1.1 Amenazas específicas de RFID

| # | Amenaza | Impacto | Probabilidad | Mitigación |
|---|---|---|---|---|
| A1 | **Lectura no autorizada** de los tags de la tienda por un tercero con un lector comercial | Un competidor conoce tu inventario exacto; un ladrón sabe qué llevarse | Media | El EPC no revela el producto sin acceso a tu base. Usar esquema con referencia de artículo no adivinable; no imprimir el EPC legible en la etiqueta |
| A2 | **Clonación de tag**: copiar el EPC de una prenda barata a otra cara | Fraude en caja, fraude en devolución | Baja-Media | Verificación de **TID** (no clonable en chip legítimo). Alerta `tid_discrepante` |
| A3 | **Reescritura del EPC** de una prenda con un lector comercial | Cambiar una prenda cara por una barata en el sistema | Baja | Bloqueo del banco EPC con **access password** derivado por HMAC |
| A4 | **Kill malicioso**: desactivar tags permanentemente | Sabotaje de inventario | Muy baja | Kill password aleatorio por tag, nunca el valor por defecto (`00000000`) |
| A5 | **Apantallamiento** (bolsa forrada de aluminio) para pasar el portal | Hurto sin alarma | **Alta** | RFID no lo resuelve. El portal es un complemento, no la defensa principal. La detección real llega en el siguiente ciclo de inventario |
| A6 | **Arrancar el tag** antes de salir | Hurto sin alarma | **Alta** | Ídem. Se detecta como merma en el ciclo, con ventana temporal corta |
| A7 | **Denegación de servicio por RF**: emisor que satura la banda | La tienda no puede inventariar | Muy baja | Detección por caída brusca de tasa de lectura; alerta `tasa_lectura_baja` |
| A8 | **Inyección de lecturas falsas** en la API | Corrupción del inventario | Media | Token de dispositivo, TLS, máscara EPC, límite de tasa, idempotencia |

> **Honestidad sobre A5 y A6**: no hay solución técnica dentro de RFID. Una bolsa de Faraday cuesta poco y funciona. El valor de TRAZA frente al hurto no es impedirlo, sino **cuantificarlo con precisión y rapidez**: saber que faltan 14 prendas concretas de la zona 3 esta semana es accionable. Saber que faltan 400 al año no lo es.

### 1.2 Amenazas de aplicación

| # | Amenaza | Mitigación |
|---|---|---|
| B1 | Robo de un handheld con token válido | Token por dispositivo, revocable desde la web. Cifrado de disco. Borrado remoto por MDM |
| B2 | Empleado que ajusta inventario para ocultar hurto propio | `stock_movements` es append-only y registra usuario y dispositivo. Auditoría de ajustes manuales por usuario |
| B3 | Escalada de privilegios entre tiendas | Políticas de Laravel verifican la ubicación en el servidor; el parámetro de la interfaz nunca es frontera de seguridad |
| B4 | Inyección SQL | Query Builder y sentencias preparadas siempre. Prohibido `DB::raw()` con entrada del usuario |
| B5 | Exfiltración de la base de datos | Sin puerto expuesto, cifrado en reposo, respaldos cifrados |
| B6 | Compromiso del borde en tienda | El borde no tiene credenciales de base de datos ni catálogo. Solo puede enviar lecturas de su propia tienda |

---

## 2. Control de acceso

### Roles

| Rol | Puede |
|---|---|
| `vendedor` | Ver stock de su tienda, buscar prendas, registrar ventas y devoluciones, ver alertas |
| `almacen` | Lo anterior + tarar, recibir, transferir, imprimir etiquetas |
| `jefe_tienda` | Lo anterior + crear y cerrar ciclos, aprobar ajustes, ver merma de su tienda |
| `supervisor_regional` | Lo anterior en varias tiendas + transferencias entre tiendas |
| `gerencia` | Solo lectura sobre todas las tiendas + informes y valorización |
| `tecnico` | Dispositivos, perfiles de lectura, diagnóstico. **Sin** permiso para ajustar stock |
| `admin` | Todo, incluida configuración de organización |

> **Separación deliberada**: `tecnico` puede cambiar la potencia de un lector pero no puede declarar una prenda perdida. `jefe_tienda` puede declarar merma pero no puede tocar el perfil de lectura. Evita que un mismo actor pueda a la vez provocar un fallo de lectura y justificar la desaparición resultante.

### Operaciones que exigen doble aprobación

| Operación | Aprobador |
|---|---|
| Ajuste manual de más de 20 unidades | `supervisor_regional` |
| Merma con valor superior a S/ 2 000 | `supervisor_regional` |
| Anulación de un lote de tags ya tarados | `admin` |
| Cambio de la máscara EPC de la organización | `admin` + registro en auditoría con motivo obligatorio |
| Cierre de ciclo con exactitud por debajo del 90 % | `jefe_tienda` con justificación escrita |

### Implementación

```php
// app/Policies/InventoryCyclePolicy.php
public function close(User $user, InventoryCycle $cycle): Response
{
    if (! $user->canAccessLocation($cycle->location_id)) {
        return Response::deny('No tienes acceso a esta tienda.');
    }

    if (! $user->hasAnyRole(['jefe_tienda', 'supervisor_regional', 'admin'])) {
        return Response::deny('Solo el jefe de tienda puede cerrar un ciclo.');
    }

    // Un ciclo con exactitud muy baja casi siempre significa que faltó
    // barrer una zona. Cerrarlo genera merma falsa y destruye la confianza
    // en el sistema, así que se exige justificación explícita.
    $accuracy = $cycle->provisionalAccuracy();
    if ($accuracy !== null && $accuracy < 90 && blank($cycle->notes)) {
        return Response::deny(
            "La exactitud provisional es {$accuracy} %. Revisa el desglose por zona "
            . 'y vuelve a barrer las zonas bajas, o escribe una justificación antes de cerrar.'
        );
    }

    return Response::allow();
}
```

---

## 3. Gestión de secretos

| Secreto | Dónde vive | Rotación |
|---|---|---|
| `APP_KEY` | Variable de entorno del central | Nunca (rotarla invalida datos cifrados) |
| Contraseña de PostgreSQL | Gestor de secretos de GitLab → variable de entorno | Anual |
| `TRAZA_TAG_ACCESS_MASTER_KEY` | **Gestor de secretos, nunca en el repositorio ni en el handheld** | Nunca (rotarla deja los tags existentes inaccesibles para escritura) |
| Token de dispositivo del borde | Hash en `devices.api_token_hash`; el valor claro solo se muestra una vez al crearlo | Ante sospecha o baja del equipo |
| Token de alta del handheld | Un solo uso, caduca a los 15 min | Por definición |
| Certificados TLS | Let's Encrypt, renovación automática | 90 días |
| Credenciales MQTT | Una por borde, en `.env.edge` | Anual o ante incidente |

### La clave maestra de access password

```
access_password(epc) = primeros_32_bits( HMAC-SHA256( clave_maestra, epc ) )
```

Propiedades:

- No hay tabla de contraseñas que robar: se recalcula.
- Conocer la contraseña de un tag no revela la de otro.
- El handheld **nunca** tiene la clave maestra: pide al servidor la contraseña del EPC concreto que va a escribir, y solo cuando la necesita.

> **Consecuencia que hay que aceptar conscientemente**: si se pierde la clave maestra, ningún tag ya bloqueado podrá reescribirse jamás. Custodiarla como se custodia una clave de firma: copia sellada fuera de línea, en dos ubicaciones.

---

## 4. Privacidad y protección de datos personales

### 4.1 Marco legal peruano

El tratamiento de datos personales en Perú se rige por la **Ley N° 29733, Ley de Protección de Datos Personales**. <cite index="9-1">El 31 de marzo de 2025 entró en vigencia el nuevo Reglamento de la Ley N° 29733, aprobado por el Decreto Supremo N° 016-2024-JUS</cite>, <cite index="2-1">que deroga el reglamento anterior de 2013 (Decreto Supremo N.º 003-2013-JUS) e introduce nuevos conceptos, obligaciones y derechos</cite>.

Cambios del nuevo reglamento que afectan a un sistema como TRAZA:

| Novedad | Implicación para el proyecto |
|---|---|
| <cite index="4-1">Creación de la figura del **Oficial de Datos Personales**, con funciones de informar y asesorar al titular del banco de datos, verificar el cumplimiento de la ley, cooperar con la ANPD y actuar como punto de contacto</cite> | Si la organización alcanza el supuesto que obliga a designarlo, hay que nombrarlo formalmente ⚠️ VERIFICAR |
| <cite index="4-1">Obligación de implementar medidas de seguridad, contar con un **documento de seguridad** y documentar y notificar incidentes de seguridad a la ANPD cuando corresponda</cite> | El documento de seguridad es un entregable del proyecto, no un trámite posterior |
| <cite index="6-1">Ámbito extendido a responsables no establecidos en Perú que ofrezcan bienes o servicios a titulares en el país o elaboren perfiles de ellos</cite> | Relevante si se contrata alojamiento o proveedores en el extranjero |

<cite index="1-1">La autoridad ha intensificado notablemente su actividad: 760 fiscalizaciones en 2025, un aumento del 67 % respecto de las 454 del año anterior, con 136 procedimientos sancionadores y multas superiores a los S/ 11 millones</cite>. <cite index="1-1">El comercio electrónico se incorporó entre los sectores más sancionados</cite>, lo que sitúa al retail dentro del foco del regulador.

> ⚠️ **VERIFICAR CON ASESORÍA LEGAL**: si la organización debe inscribir bancos de datos personales, si le corresponde designar Oficial de Datos Personales según el cronograma vigente, y los plazos de adecuación aplicables. Este documento no sustituye asesoría legal. Consultar `https://www.gob.pe/anpd`.

### 4.2 Qué datos personales trata TRAZA

Buena noticia: **muy pocos**. El diseño lo evita deliberadamente.

| Dato | ¿Personal? | Justificación |
|---|---|---|
| EPC del tag | **No** por sí solo | Identifica una prenda, no una persona |
| Lecturas de tag con hora y antena | **No** mientras la prenda está en stock | Es inventario |
| Datos de empleados (nombre, correo, rol) | **Sí** | Relación laboral. Base legal: ejecución del contrato |
| Registro de qué empleado hizo cada movimiento | **Sí** | Base legal: interés legítimo (control de inventario y auditoría). **Debe informarse al empleado** |
| Datos de clientes | **No se tratan** en TRAZA | Las ventas se enlazan por número de comprobante del POS, sin datos del cliente |
| Vídeo de cámaras correlacionado con alarmas | **Sí, y sensible** | **Fuera del alcance de TRAZA.** Ver §4.4 |

### 4.3 El problema del tag vivo tras la venta

Una vez vendida la prenda, si el tag sigue activo, cualquiera con un lector puede detectarlo. Eso permitiría, en teoría, reconocer a una persona por el conjunto de EPC que lleva encima.

Decisiones de TRAZA:

| Decisión | Motivo |
|---|---|
| **No se hace kill del tag** | Es irreversible; se pierde la verificación de devoluciones |
| **Se usa hangtag retirable, no etiqueta cosida** | El tag se retira en caja junto con la etiqueta de precio. El cliente sale sin tag |
| **Se informa al cliente** | Cartel visible en caja y en la puerta, en lenguaje llano |
| **El EPC no se asocia nunca a datos del cliente** | Aunque alguien leyera el tag, TRAZA no puede vincularlo a una persona |
| **Tras la venta, el EPC deja de rastrearse** | Las lecturas de EPC en estado `vendido` se descartan salvo en el flujo de devolución |

Texto sugerido para el cartel:

> **Etiquetas con chip de inventario**
> Las prendas de esta tienda llevan una etiqueta con un chip que usamos para controlar el stock. La etiqueta se retira al pagar. No contiene datos personales tuyos ni sabemos quién compra qué a partir de ella. Si prefieres que la retiremos antes, pídelo en caja.

### 4.4 Lo que TRAZA deliberadamente NO hace

| Función técnicamente posible | Por qué no se implementa |
|---|---|
| Rastrear el recorrido de un cliente por la tienda mediante los tags que toca | Elaboración de perfiles de comportamiento. Requiere base legal específica e información al titular; el beneficio no compensa el riesgo regulatorio y reputacional |
| Correlacionar alarmas de portal con grabaciones de videovigilancia | Trata datos biométricos/imágenes. Si el negocio lo requiere, debe tratarse como un sistema aparte, con su propia evaluación de impacto y base legal |
| Identificar clientes recurrentes por los tags de prendas compradas antes | Igual que el anterior, agravado |
| Medir el tiempo que cada empleado tarda en cada zona para evaluarlo | Vigilancia laboral. El sistema mide **zonas**, no personas |

> Esta sección es tan importante como cualquier decisión técnica. La tentación de añadir estas funciones es grande porque los datos ya están ahí. **La respuesta por defecto es no**, y cualquier excepción exige asesoría legal, información al titular y una decisión documentada de la dirección.

---

## 5. Cifrado

| Dato en | Medida |
|---|---|
| **Tránsito, web ↔ API** | TLS 1.3, HSTS, certificados Let's Encrypt |
| **Tránsito, borde ↔ central** | TLS sobre WireGuard (doble capa, deliberadamente) |
| **Tránsito, MQTT** | TLS mutuo en puerto 8883 |
| **Reposo, base de datos** | Cifrado de volumen a nivel de disco del servidor |
| **Reposo, respaldos** | `gpg` o cifrado del lado del servidor en MinIO/S3 |
| **Reposo, handheld** | Cifrado de disco de Android obligatorio; token en `EncryptedSharedPreferences` |
| **Reposo, buffer del borde** | El buffer SQLite contiene EPC y horas, no datos personales. Sin cifrar; se protege por acceso al equipo |

---

## 6. Auditoría

Toda operación sensible deja rastro en `audit_logs`:

```php
// app/Observers/AuditObserver.php
public function updated(Model $model): void
{
    $dirty = $model->getDirty();
    if (empty($dirty)) return;

    AuditLog::create([
        'organization_id' => $model->organization_id ?? null,
        'user_id'      => auth()->id(),
        'device_id'    => request()->attributes->get('device_id'),
        'action'       => 'updated',
        'subject_type' => $model::class,
        'subject_id'   => $model->getKey(),
        'changes'      => [
            'before' => Arr::only($model->getOriginal(), array_keys($dirty)),
            'after'  => $dirty,
        ],
        'ip_address'   => request()->ip(),
        'user_agent'   => request()->userAgent(),
    ]);
}
```

Se audita siempre:

- Cambios en `tags` (fuera del flujo normal de movimientos).
- Todo ajuste manual de stock.
- Cambios de rol o de permisos.
- Cambios en perfiles de lectura y potencia de antena.
- Rotación de tokens de dispositivo.
- Cambios en la configuración de la organización.
- Accesos fallidos repetidos.

### Informes de auditoría periódicos

| Informe | Frecuencia | Quién lo revisa |
|---|---|---|
| Ajustes manuales por usuario | Mensual | Supervisor regional |
| Mermas declaradas por usuario y tienda | Mensual | Gerencia |
| Cambios de configuración de dispositivos | Mensual | Responsable técnico |
| Accesos fuera de horario comercial | Semanal | Automático, genera alerta |

> El informe de **ajustes manuales por usuario** es el más útil contra el fraude interno. Un empleado que concentra un número anormal de ajustes negativos merece una conversación, aunque cada ajuste individual parezca razonable.

---

## 7. Respuesta a incidentes

### Clasificación

| Nivel | Ejemplo | Respuesta |
|---|---|---|
| **P1 — Crítico** | Fuga de base de datos; compromiso del servidor central | Aislar, notificar a dirección en 1 h, evaluar notificación a la ANPD |
| **P2 — Alto** | Handheld robado; token comprometido | Revocar token en < 1 h, borrado remoto, auditar actividad del token |
| **P3 — Medio** | Discrepancia entre proyección y movimientos | Congelar ajustes automáticos, investigar, corregir con movimientos compensatorios |
| **P4 — Bajo** | Falsos positivos del portal por encima del umbral | Recalibrar en la siguiente ventana de mantenimiento |

### Notificación de incidentes de seguridad

El nuevo reglamento incorpora <cite index="4-1">la obligación de documentar y notificar incidentes de seguridad a la ANPD cuando corresponda</cite>.

Procedimiento interno:

1. **Contener** primero: revocar accesos, aislar el sistema afectado.
2. **Documentar** desde el minuto uno: qué, cuándo se detectó, qué datos, cuántos titulares.
3. **Evaluar** con asesoría legal si el incidente alcanza el umbral de notificación.
4. **Notificar** a la ANPD en el plazo aplicable si corresponde ⚠️ VERIFICAR plazo exacto en el reglamento vigente.
5. **Informar** a los titulares afectados si el riesgo lo exige.
6. **Post-mortem** escrito, sin buscar culpables, con acciones concretas y responsable asignado.

---

## 8. Lista de verificación previa a producción

```
Infraestructura
[ ] TLS 1.3 en todos los extremos públicos; HSTS activo
[ ] Base de datos sin puerto expuesto a internet
[ ] MQTT solo por 8883 con TLS y ACL por tópico
[ ] WireGuard operativo en todos los bordes
[ ] Respaldos automáticos verificados, con una restauración de prueba hecha
[ ] Secretos fuera del repositorio; escaneo de secretos activo en CI

Aplicación
[ ] APP_DEBUG=false en producción
[ ] Límites de tasa activos en todos los endpoints
[ ] Políticas de autorización con pruebas para cada rol
[ ] Cabeceras de seguridad (CSP, X-Frame-Options, X-Content-Type-Options)
[ ] Auditoría activa en todos los modelos sensibles
[ ] Dependencias sin vulnerabilidades conocidas (composer audit, npm audit)

RFID
[ ] Access password derivado y banco EPC bloqueado en tags de producción
[ ] Kill password aleatorio por tag, nunca el valor por defecto
[ ] Máscara EPC configurada y verificada con lecturas reales
[ ] Prefijo de pruebas rechazado en producción

Cumplimiento
[ ] Cartel informativo al cliente instalado en caja y puerta
[ ] Empleados informados del registro de sus acciones en el sistema
[ ] Documento de seguridad redactado
[ ] Asesoría legal consultada sobre Ley 29733 y su reglamento vigente
[ ] Homologación MTC de los equipos verificada (documento 01, §3)
[ ] Procedimiento de respuesta a incidentes escrito y con responsable asignado
```
