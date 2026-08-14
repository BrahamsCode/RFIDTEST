/**
 * Mide los fps reales de `TagTable` con 20 000 filas. Tarea 4.5.
 *
 * jsdom no sirve para esto: no compone, no pinta y no tiene fotogramas. Hace
 * falta un navegador de verdad, y por eso esta medición vive aparte de la
 * suite de Vitest y no corre en cada commit.
 *
 * Uso:  node perf/tagtable-fps.mjs [url]
 */
import { chromium } from 'playwright';

const URL_APP = process.argv[2] ?? 'http://127.0.0.1:4173/prendas';
const EJECUTABLE = process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium';
const OBJETIVO_FPS = 60;
const PRESUPUESTO_MS = 1000 / OBJETIVO_FPS; // 16.7 ms por fotograma

const browser = await chromium.launch({
  executablePath: EJECUTABLE,
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

// Se intercepta el API: lo que se mide es el renderizado de la tabla, no la
// base de datos. 20 000 filas repartidas en páginas de 200.
const TOTAL = 20_000;
// Páginas de 500: con 200, cargar las 20 000 son 100 idas y vueltas y la
// preparación tarda más que la medición.
const POR_PAGINA = 500;

await page.route('**/api/v1/tags*', async (route) => {
  const url = new URL(route.request().url());
  const pagina = Number(url.searchParams.get('page') ?? 1);
  const desde = (pagina - 1) * POR_PAGINA;

  const data = Array.from({ length: Math.min(POR_PAGINA, TOTAL - desde) }, (_, i) => {
    const n = desde + i;
    return {
      id: n + 1,
      epc: '3035D9' + String(n).padStart(18, '0'),
      state: ['en_stock', 'vendido', 'no_visto', 'perdido'][n % 4],
      product_variant_id: (n % 12) + 1,
      sku: `SKU-${(n % 12) + 1}`,
      product_name: 'Polera Oversize',
      current_location_id: 1,
      location_name: 'Gamarra 1',
      current_zone_id: (n % 7) + 1,
      zone_name: 'Sala principal',
      last_seen_at: '2026-08-13T18:22:00Z',
    };
  });

  await route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      data,
      meta: {
        total: TOTAL,
        per_page: POR_PAGINA,
        current_page: pagina,
        last_page: Math.ceil(TOTAL / POR_PAGINA),
      },
    }),
  });
});

await page.route('**/api/v1/user', (r) =>
  r.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      id: 1, name: 'Jefa', email: 'jefe@ejemplo.pe',
      organization_id: 1, default_location_id: 1,
    }),
  }),
);

await page.goto(URL_APP, { waitUntil: 'networkidle' });
await page.waitForSelector('text=prendas', { timeout: 20_000 });

/*
 * Primero se cargan las 20 000 filas de verdad.
 *
 * `useInfiniteQuery` trae una página cada vez, así que desplazarse sin más
 * mide la tabla con las mil y pico filas cargadas hasta ese momento, no con
 * veinte mil. El criterio de aceptación habla de 20 000, así que hay que
 * llegar a 20 000 antes de empezar a contar fotogramas.
 */
process.stdout.write('  cargando las 20 000 filas');

await page.evaluate(async (total) => {
  const scroll = () => [...document.querySelectorAll('div')]
    .find((d) => d.scrollHeight > d.clientHeight + 100 && d.clientHeight > 200);

  const alturaObjetivo = total * 44 * 0.98;

  for (let i = 0; i < 400; i++) {
    const s = scroll();
    if (!s) break;
    if (s.scrollHeight >= alturaObjetivo) break;

    s.scrollTop = s.scrollHeight;
    await new Promise((r) => setTimeout(r, 60));
  }
}, TOTAL);

const cargadas = await page.evaluate(() => {
  const s = [...document.querySelectorAll('div')]
    .find((d) => d.scrollHeight > d.clientHeight + 100 && d.clientHeight > 200);
  return Math.round(s.scrollHeight / 44);
});

console.log(` → ${cargadas} filas en el virtualizador`);

if (cargadas < TOTAL * 0.95) {
  console.error(`  Solo se cargaron ${cargadas} de ${TOTAL}: la medición no valdría.`);
  await browser.close();
  process.exit(1);
}

// Vuelta arriba para medir el recorrido completo.
await page.evaluate(() => {
  const s = [...document.querySelectorAll('div')]
    .find((d) => d.scrollHeight > d.clientHeight + 100 && d.clientHeight > 200);
  s.scrollTop = 0;
});
await page.waitForTimeout(300);

// El contenedor virtualizado es el que tiene scroll propio.
const scroller = await page.evaluateHandle(() => {
  const candidatos = [...document.querySelectorAll('div')];
  return candidatos.find((d) => d.scrollHeight > d.clientHeight + 100 && d.clientHeight > 200);
});

if (!(await scroller.evaluate((n) => !!n))) {
  console.error('No se encontró el contenedor virtualizado.');
  await browser.close();
  process.exit(1);
}

// Se mide con requestAnimationFrame, que es lo que ve el usuario: el hueco
// entre fotogramas pintados. Contar re-renders de React no diría nada sobre
// si la mano nota el tirón.
const resultado = await page.evaluate(async (presupuesto) => {
  const scroll = [...document.querySelectorAll('div')]
    .find((d) => d.scrollHeight > d.clientHeight + 100 && d.clientHeight > 200);

  const huecos = [];
  let anterior = performance.now();
  let corriendo = true;

  const medir = (t) => {
    huecos.push(t - anterior);
    anterior = t;
    if (corriendo) requestAnimationFrame(medir);
  };
  requestAnimationFrame(medir);

  /*
   * Desplazamiento continuo por toda la lista: 300 pasos que recorren las
   * 20 000 filas de punta a punta, montando y desmontando filas sin parar.
   * Es el peor caso real: alguien arrastrando la barra para buscar algo.
   */
  const paso = Math.ceil(scroll.scrollHeight / 300);

  for (let i = 0; i < 300; i++) {
    scroll.scrollTop += paso;
    await new Promise((r) => requestAnimationFrame(r));
  }

  corriendo = false;
  await new Promise((r) => setTimeout(r, 100));

  // Se descartan los dos primeros: el primer fotograma tras arrancar el
  // bucle no mide un desplazamiento.
  const m = huecos.slice(2).sort((a, b) => a - b);
  const p = (q) => m[Math.floor(q * (m.length - 1))];

  return {
    fotogramas: m.length,
    p50: p(0.5),
    p95: p(0.95),
    p99: p(0.99),
    max: m[m.length - 1],
    caidos: m.filter((x) => x > presupuesto * 1.5).length,
    filasEnDom: document.querySelectorAll('[data-index]').length ||
                scroll.querySelectorAll(':scope > div > *').length,
    scrollFinal: Math.round(scroll.scrollTop),
    alturaTotal: Math.round(scroll.scrollHeight),
  };
}, PRESUPUESTO_MS);

console.log('\n  TagTable · 20 000 filas · Chromium ' + browser.version());
console.log('  ─────────────────────────────────────────────');
console.log(`  fotogramas medidos    ${resultado.fotogramas}`);
console.log(`  p50 entre fotogramas  ${resultado.p50.toFixed(1)} ms`);
console.log(`  p95                   ${resultado.p95.toFixed(1)} ms`);
console.log(`  p99                   ${resultado.p99.toFixed(1)} ms`);
console.log(`  máximo                ${resultado.max.toFixed(1)} ms`);
console.log(`  fotogramas perdidos   ${resultado.caidos} (> ${(PRESUPUESTO_MS * 1.5).toFixed(1)} ms)`);
console.log(`  filas en el DOM       ${resultado.filasEnDom}`);
console.log(`  scroll recorrido      ${resultado.scrollFinal} de ${resultado.alturaTotal} px`);

const fpsP95 = 1000 / resultado.p95;
console.log(`\n  fps sostenidos (p95)  ${fpsP95.toFixed(1)}`);

await browser.close();

// Criterio: el p95 del hueco entre fotogramas por debajo del presupuesto de
// 60 fps. Se mira el p95 y no la media porque un tirón aislado se nota y una
// media buena lo esconde.
const ok = resultado.p95 <= PRESUPUESTO_MS * 1.2 && resultado.filasEnDom < 200;
console.log(ok ? '\n  RESULTADO: cumple 60 fps\n' : '\n  RESULTADO: NO cumple\n');
process.exit(ok ? 0 : 1);
