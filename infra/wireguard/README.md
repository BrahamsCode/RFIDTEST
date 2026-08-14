# WireGuard

Todo el tráfico tienda↔central va por aquí. La API del central **no se
expone a internet** salvo el endpoint de la web (`docs/11` §9), así que sin
túnel un borde no habla con nadie.

| Fichero | Versionado |
|---|---|
| `wg0.conf.example` | Sí — plantilla del borde |
| `servidor.conf.example` | Sí — plantilla del central |
| `wg0.conf` | **No** — contiene la clave privada del borde |
| `privatekey`, `publickey`, `preshared` | **No** — son secretos |

`.gitignore` ya excluye todo lo que no sea `*.example` y este README. Si
añades un fichero nuevo aquí, comprueba antes de hacer commit que no lleva
material de clave dentro.

## Dar de alta una tienda

Las claves se generan **en el equipo de la tienda**: la privada no debe
viajar, ni por correo ni por WhatsApp ni pegada en un ticket de soporte.

```bash
# --- en el borde de la tienda ---
umask 077
wg genkey | tee privatekey | wg pubkey > publickey
wg genpsk > preshared          # clave compartida, capa extra por par

cat publickey preshared        # esto es lo único que se manda al central
```

```bash
# --- en el central ---
# Añadir el par con la IP que le toque dentro del túnel.
wg set wg0 peer <CLAVE_PUBLICA_DEL_BORDE> \
    preshared-key /ruta/al/preshared \
    allowed-ips 10.8.0.11/32
wg-quick save wg0
```

Después, en el borde, copiar `wg0.conf.example` a `wg0.conf` y rellenar la
clave privada, la clave pública del central y la IP asignada.

## Direccionamiento

| Rango | Quién |
|---|---|
| `10.8.0.1` | Central: API, MQTT, Prometheus |
| `10.8.0.11`–`10.8.0.99` | Bordes, uno por tienda |
| `10.8.0.101`–`10.8.0.199` | Portátiles de soporte |

Se lleva apuntado en el inventario de dispositivos. Repetir una IP no da un
error claro: da un túnel que funciona a ratos, según qué par contestó
último, y es de las averías más molestas de diagnosticar en remoto.

## Por qué `allowed-ips` es de un solo host

En el central, cada borde entra con `/32` y no con `/24`. Con `/24` un borde
comprometido podría suplantar la dirección de otra tienda y publicar
lecturas a su nombre; WireGuard asocia cada IP de origen a la clave que la
tiene permitida (*cryptokey routing*), así que el `/32` lo impide por
construcción. Es el mismo razonamiento que la ACL de Mosquitto por tienda.

En el borde, en cambio, `AllowedIPs = 10.8.0.0/24` y **no** `0.0.0.0/0`: por
el túnel va lo que habla con el central, no la navegación del equipo. Con
`0.0.0.0/0` todo el tráfico de la tienda saldría por el central, que ni lo
necesita ni quiere pagar ese ancho de banda.

## `PersistentKeepalive`

Los bordes están detrás del router de la tienda, casi siempre con NAT y sin
IP fija. Sin `PersistentKeepalive = 25`, la traducción NAT caduca en cuanto
hay unos minutos de silencio y **el central deja de poder iniciar la
conexión**: el borde sigue enviando lecturas sin enterarse de nada, pero el
SSH de soporte no entra hasta que al borde le toque transmitir. Es la
diferencia entre resolver una incidencia en remoto y coger un taxi hasta
Gamarra.

## Comprobar que está bien

```bash
docker compose -f docker-compose.edge.yml exec wireguard wg show
```

Lo que interesa es `latest handshake`: si pasa de dos minutos, el túnel está
caído aunque la interfaz figure levantada. El *healthcheck* del contenedor
solo comprueba que la interfaz existe, que es una condición más débil.

```bash
# Extremo a extremo: la API del central responde por dentro del túnel.
docker compose -f docker-compose.edge.yml exec edge \
    wget -qO- https://10.8.0.1/api/v1/health
```

## Qué pasa cuando el túnel se cae

Nada urgente, y es a propósito. El borde comparte pila de red con el
contenedor de WireGuard (`network_mode: service:wireguard`), así que sin
túnel **no tiene otra salida**: las lecturas se acumulan en el buffer SQLite
local y se envían solas al volver. Medido en la tarea 2.6: 10 minutos sin
central, 53 910 lecturas retenidas, 0 perdidas.

Lo que sí conviene vigilar es el disco. El buffer tiene tope de 2 000 000 de
filas y a partir de ahí descarta **las más antiguas**; a los ritmos medidos
eso da varios días de margen, pero la alerta `BordeSinResponder` de
`infra/prometheus/alerts.yml` salta mucho antes.
