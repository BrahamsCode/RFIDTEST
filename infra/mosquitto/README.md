# Mosquitto

| Fichero | Entorno | Versionado |
|---|---|---|
| `mosquitto.conf` | Desarrollo: 1883 en claro, anónimo | Sí |
| `mosquitto.prod.conf` | Producción: solo 8883 con TLS y ACL | Sí |
| `acl` | Permisos por tópico y por tienda | Sí |
| `passwd` | Contraseñas de los bordes | **No** — es un secreto |
| `certs/` | Certificados TLS | **No** — son secretos |

## Preparar producción

```bash
# 1. Certificados. En producción se usa Let's Encrypt con renovación
#    automática; para una prueba interna vale un CA propio.
mkdir -p certs

# 2. Un usuario por borde. El primero lleva -c para crear el fichero.
mosquitto_passwd -c passwd edge-lim01
mosquitto_passwd    passwd edge-lim02
mosquitto_passwd    passwd traza-api

# 3. Añadir el bloque correspondiente en `acl` para cada borde nuevo.
```

Sin `passwd` y sin `certs/`, el contenedor de producción **no arranca**. Es
deliberado: es preferible que falle el despliegue a que se levante un broker
sin autenticar.

## Por qué la ACL no es opcional

Cada borde publica solo en el tópico de su tienda. Sin ACL, un borde
comprometido en una tienda podría publicar eventos de portal a nombre de
cualquier otra: alarmas falsas, o peor, silenciar las reales.
