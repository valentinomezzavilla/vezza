'use strict';

(() => {
  const { el } = Panel;
  const form = document.getElementById('filtros');
  const fDesde = document.getElementById('desde');
  const fHasta = document.getElementById('hasta');
  const fCliente = document.getElementById('cliente');
  const cont = document.getElementById('reporte');
  const params = new URLSearchParams(location.search);
  const base = Panel.aFecha(Panel.hoy());
  fDesde.value = params.get('desde') || Panel.iso(new Date(base.getFullYear(), base.getMonth() - 5, 1));
  fHasta.value = params.get('hasta') || Panel.iso(new Date(base.getFullYear(), base.getMonth() + 1, 0));

  function statMonto(label, valor, moneda) {
    return el('div', { class: 'stat' },
      el('div', { class: 'stat-label', text: label }),
      el('div', { class: `stat-valor${Number(valor) < 0 ? ' negativo' : ''}`, text: Panel.fmtMonto(valor, moneda) }));
  }

  function fila(f, moneda, max, conGastos) {
    const mesTexto = Panel.aFecha(`${f.mes}-01`).toLocaleDateString('es-AR', { month: 'short', year: 'numeric' });
    const barraIng = el('div', { class: 'barra barra-ing' });
    barraIng.style.width = `${(Number(f.ingresos) / max) * 100}%`;
    let barraGas = null;
    if (conGastos) {
      barraGas = el('div', { class: 'barra barra-gas' });
      barraGas.style.width = `${(Number(f.gastos) / max) * 100}%`;
    }
    return el('tr', {},
      el('td', { 'data-label': 'Mes', text: mesTexto }),
      el('td', { class: 'num', 'data-label': 'Ingresos', text: Panel.fmtMonto(f.ingresos, moneda) }),
      conGastos ? el('td', { class: 'num', 'data-label': 'Gastos', text: Panel.fmtMonto(f.gastos, moneda) }) : null,
      el('td', { class: `num${Number(f.balance) < 0 ? ' negativo' : ''}`, 'data-label': 'Balance', text: Panel.fmtMonto(f.balance, moneda) }),
      el('td', { 'data-label': 'Gráfico' }, el('div', { class: 'barras', 'aria-hidden': 'true' }, barraIng, barraGas)));
  }

  function bloqueMoneda(r, moneda) {
    const conGastos = r.incluye_gastos;
    const filas = r.meses.map((m) => ({
      mes: m.mes,
      ...(m.monedas.find((x) => x.moneda === moneda) || { ingresos: '0', gastos: '0', balance: '0' }),
    }));
    const max = Math.max(1, ...filas.map((f) => Math.max(Number(f.ingresos), Number(f.gastos || 0))));
    const total = r.totales.find((t) => t.moneda === moneda);
    const cabeza = ['Mes', 'Ingresos', conGastos ? 'Gastos' : null, 'Balance', ''].filter((h) => h !== null);
    return el('section', { class: 'seccion' },
      el('h2', { text: moneda }),
      el('div', { class: 'stats' },
        statMonto('Ingresos', total.ingresos, moneda),
        conGastos ? statMonto('Gastos', total.gastos, moneda) : null,
        statMonto('Balance', total.balance, moneda)),
      el('table', { class: 'tabla' },
        el('thead', {}, el('tr', {}, cabeza.map((h, i) => el('th', { class: i > 0 && h ? 'num' : null, scope: 'col', text: h })))),
        el('tbody', {}, filas.map((f) => fila(f, moneda, max, conGastos)))));
  }

  async function cargar() {
    history.replaceState(null, '', location.pathname + Panel.qs({ desde: fDesde.value, hasta: fHasta.value, cliente_id: fCliente.value }));
    try {
      const r = await Panel.get('/api/reportes/balance', { desde: fDesde.value, hasta: fHasta.value, cliente_id: fCliente.value });
      const monedas = r.totales.map((t) => t.moneda);
      Panel.llenar(cont,
        r.incluye_gastos ? null : el('p', { class: 'aviso', text: 'Filtrado por cliente: se muestran solo sus ingresos, porque los gastos no se asignan a clientes.' }),
        monedas.length ? monedas.map((m) => bloqueMoneda(r, m)) : Panel.vacio('No hay movimientos en ese período.'));
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    cargar();
  });

  Panel.clientes().then((clientes) => {
    fCliente.append(...clientes.map((c) => el('option', { value: String(c.id), text: c.nombre })));
    fCliente.value = params.get('cliente_id') || '';
    return cargar();
  }).catch(Panel.manejarError);
})();
