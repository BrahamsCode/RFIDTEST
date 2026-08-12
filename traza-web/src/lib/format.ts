const numberFormat = new Intl.NumberFormat('es-PE');
const currencyFormat = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' });

export function formatNumber(value: number): string {
  return numberFormat.format(value);
}

export function formatCurrency(value: number): string {
  return currencyFormat.format(value);
}

export function formatPercent(value: number, digits = 1): string {
  return `${value.toFixed(digits)} %`;
}

/** Los EPC se agrupan de 4 en 4 para poder compararlos de un vistazo. */
export function formatEpc(epc: string): string {
  return epc.toUpperCase().replace(/(.{4})/g, '$1 ').trim();
}
