# traza-handheld

Aplicación Android para el lector de mano. Kotlin + Jetpack Compose.
Ver `docs/09-app-handheld.md`.

## Estructura

| Módulo | Qué es |
|---|---|
| `core:reader` | Kotlin JVM puro. Abstracción del lector y lector falso. **Sin dependencias de Android**, se compila y se prueba sin SDK ni hardware |
| `core:domain` | Kotlin JVM puro. La lógica de los cinco modos: deduplicación de inventario, tarado, Geiger, política de subida y alta por QR |
| `app` | Aplicación Android: Compose, Room, WorkManager y Hilt. Solo entra en la compilación si hay SDK |

Que la lógica viva en `core:domain` y no en los ViewModel es lo que permite
medir de verdad: la deduplicación de 20 000 EPC se comprueba en una prueba de
JUnit corriente, sin emulador. Los ViewModel se limitan a conectar el lector,
escribir en Room y exponer el estado.

Esa separación es deliberada: permite que CI ejecute las pruebas del lector
sin instalar el SDK de Android, y que la lógica se desarrolle sin hardware.

## Puesta en marcha

```bash
# Solo los módulos de lógica (no requieren SDK de Android)
./gradlew :core:reader:test :core:domain:test

# Aplicación completa: requiere ANDROID_HOME o local.properties con sdk.dir
ANDROID_HOME=/ruta/al/sdk ./gradlew :app:assembleDebug
```

Sin `ANDROID_HOME`, `settings.gradle.kts` excluye `:app` y el resto compila
igual. Es lo que permite que CI ejecute las pruebas de lógica sin descargar
cientos de MB de SDK en cada rama.

## El contexto de uso manda

Quien la usa está de pie, con 800 g en una mano, mirando la estantería y no la
pantalla, durante tramos de 20 a 45 minutos, a veces sin red. De ahí:

- El gatillo físico escanea. Nunca abre menús ni confirma diálogos.
- Un solo número dominante por pantalla, a 48 sp o más.
- Realimentación háptica y sonora: la vista está en la estantería.
- Nunca bloquear por red. Todo escribe primero en local.
- Modo oscuro por defecto.

## Perfiles de lectura

`ReadProfile` valida sus propios invariantes (sesión Gen2 en S0–S3, target A o B).
El perfil `TARADO` usa potencia deliberadamente baja: leer la caja entera de la
trastienda al tarar una prenda es el fallo clásico.

Ese perfil usa además la sesión **S0**, como manda la tabla de `docs/09` §4.
No es un detalle de estilo: una sesión con persistencia calla al tag tras la
primera respuesta, y entonces el `minReadCount = 2` del propio perfil no se
alcanzaría nunca.

## Estado de implementación

| Pieza | Estado |
|---|---|
| Estructura Gradle multi-módulo | Hecho |
| `RfidReader` y `ReadProfile` | Hecho |
| `FakeRfidReader` determinista | Hecho — tarea 5.1 |
| Manifiesto y actividad base | Hecho (marcador de posición) |
| Modo inventario | Hecho — tarea 5.2 |
| Sincronización offline con Room y WorkManager | Hecho — tarea 5.3 |
| Modo tarado con las 4 señales | Hecho — tarea 5.4 |
| Modo búsqueda (Geiger) | Hecho — tarea 5.5 |
| Alta por QR y credenciales cifradas | Hecho — tarea 5.6 |
| Modos consulta y recepción | Pendiente |
| Pruebas instrumentadas de Compose | Pendiente — necesitan emulador |
| SDK real (Zebra/Chainway) | Pendiente — tarea 5.7, depende de la 0.1 |

## Lo que no está verificado

El APK se compila y las 57 pruebas de JVM pasan, pero **nada de esto se ha
ejecutado en un dispositivo ni en un emulador**. Queda sin comprobar todo lo
que solo se ve al usarlo: que el gatillo físico llegue como `TriggerPressed`,
que la háptica se note con guantes, que los cuatro tonos se distingan en una
galería con ruido, y que la sincronización sobreviva de verdad a una mañana
en modo avión. Son las tareas 0.1 y 5.7.
