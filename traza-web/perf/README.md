# Mediciones de rendimiento

Estas dos mediciones **no corren en CI** y es a propósito: necesitan un
navegador de verdad y servicios levantados, y un número de fps medido en un
runner compartido no significa nada. Se ejecutan a mano cuando se toca la
tabla de prendas o el camino de difusión.

## `tagtable-fps.mjs` — tarea 4.5

Comprueba el criterio de «60 fps con 20 000 filas». jsdom no sirve: no
compone, no pinta y no tiene fotogramas.

```bash
npm run build && npm run preview        # en otra terminal, sirve en :4173
node perf/tagtable-fps.mjs
```

El API se intercepta desde Playwright, así que no hace falta backend: lo que
se mide es el renderizado, no la base de datos. El script carga primero las
20 000 filas **completas** y solo entonces mide; una versión anterior
recorría las ~1 200 que había cargadas y daba un resultado bonito y falso.

Última medición: 20 000 filas, 879 488 px recorridos, 299 fotogramas,
p95 16,7 ms = **59,9 fps**, 0 fotogramas perdidos, 24 filas en el DOM.

## `reverb-latencia.mjs` — tareas 3.4 y 4.2

Mide el retardo real de un evento de difusión sobre Reverb. Criterio: menos
de 3 s.

```bash
# en traza-api
php artisan reverb:start --port=8085 &
# en traza-web
node perf/reverb-latencia.mjs private-inventory-cycle.1 5
```

Se suscribe firmando el canal privado con HMAC, igual que hace el navegador.
Esto importa: la primera versión escuchaba un canal público, no recibía nada
y así fue como apareció el fallo de `broadcastAs()` —sin él Echo escucha
`InventoryCycleProgressed` y el servidor emite
`App\Events\InventoryCycleProgressed`, y no salta ningún error en ninguna
parte—.

Última medición: retardo por debajo de 1 ms en local.
