# traza-handheld

Aplicación Android para el lector de mano. Kotlin + Jetpack Compose.
Ver `docs/09-app-handheld.md`.

## Estructura

| Módulo | Qué es |
|---|---|
| `core:reader` | Kotlin JVM puro. Abstracción del lector y lector falso. **Sin dependencias de Android**, se compila y se prueba sin SDK ni hardware |
| `app` | Aplicación Android. Solo entra en la compilación si hay SDK |

Esa separación es deliberada: permite que CI ejecute las pruebas del lector
sin instalar el SDK de Android, y que la lógica se desarrolle sin hardware.

## Puesta en marcha

```bash
# Solo el módulo del lector (no requiere SDK de Android)
gradle :core:reader:test

# Aplicación completa: requiere ANDROID_HOME o local.properties con sdk.dir
gradle :app:assembleDebug
```

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

## Estado de implementación

| Pieza | Estado |
|---|---|
| Estructura Gradle multi-módulo | Hecho |
| `RfidReader` y `ReadProfile` | Hecho |
| `FakeRfidReader` determinista | Hecho — tarea 5.1 |
| Manifiesto y actividad base | Hecho (marcador de posición) |
| Modo inventario | Pendiente — tarea 5.2 |
| Sincronización offline con Room | Pendiente — tarea 5.3 |
| Modo tarado | Pendiente — tarea 5.4 |
| Modo búsqueda (Geiger) | Pendiente — tarea 5.5 |
| SDK real (Zebra/Chainway) | Pendiente — tarea 5.7, depende de la 0.1 |
