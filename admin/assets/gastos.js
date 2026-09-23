'use strict';

(() => {
  const { el } = Panel;
  const contSusc = document.getElementById('suscripciones');
  const contGastos = document.getElementById('gastos');
  const fDesde = document.getElementById('filtro-desde');
  const fHasta = document.getElementById('filtro-hasta');
  const fCat = document.getElementById('filtro-categoria');
  const base = Panel.aFecha(Panel.hoy());
  fDesde.value = Panel.iso(new Date(base.getFullYear(), base.getMonth(), 1));
  fHasta.value = Panel.iso(new Date(base.getFullYear(), base.getMonth() + 1, 0));

  let subs = [];
  let gastos = [];
  let categorias = [...CATEGORIAS_BASE];

  function actualizarCategorias() {
    const set = new Set(CATEGORIAS_BASE);
    for (const x of [...subs, ...gastos]) if (x.categoria) set.add(x.categoria);
    categorias = [...set].sort((a, b) => a.localeCompare(b, 'es'));
    Panel.llenar(document.getElementById('categorias'), categorias.map((c) => el('option', { value: c })));
  }

  const recargarTodo = () => Promise.all([cargarSuscripciones(), cargarGastos()]).catch(Panel.manejarError);

  function alerta(s) {
    if (!s.activa) return Panel.badge('Pausada');
    const d = s.dias_restantes;
    if (d < 0) return Panel.badge(`Venció hace ${-d} d`, 'danger');
    if (d === 0) return Panel.badge('Vence hoy', 'warn');
    return Panel.badge(`En ${d} d`, d <= 7 ? 'warn' : '');
  }

  function itemSuscripcion(s) {
    const detalle = [
      Panel.fmtMonto(s.monto, s.moneda),
      Panel.ETQ.frecuencia[s.frecuencia],
      `próximo ${Panel.fmtFecha(s.fecha_proximo_cobro)}`,
      s.categoria,
    ].filter(Boolean).join(' · ');
    return el('div', { class: 'item' },
      el('div', { class: 'item-top' }, el('span', { class: 'item-titulo', text: s.servicio }), alerta(s)),
      el('div', { class: 'item-sub', text: detalle }),
      el('div', { class: 'item-acciones' },
        s.activa ? Panel.boton('Registrar pago', async () => {
          if (await Suscripciones.pagar(s)) {
            Panel.toast('Pago registrado');
            recargarTodo();
          }
        }, 'chico') : null,
        Panel.boton('Editar', async () => { if (await Suscripciones.editar(s, categorias)) recargarTodo(); }, 'chico')));
  }

  async function cargarSuscripciones() {
    subs = await Panel.get('/api/suscripciones');
    actualizarCategorias();
    Panel.llenar(contSusc, subs.length ? subs.map(itemSuscripcion) : Panel.vacio('Todavía no cargaste suscripciones.'));
  }

  function itemGasto(g) {
    return el('button', {
      type: 'button', class: 'item',
      onclick: async () => { if (await Gastos.editar(g, categorias)) cargarGastos().catch(Panel.manejarError); },
    },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-titulo', text: g.concepto }),
        el('span', { class: 'item-titulo', text: Panel.fmtMonto(g.monto, g.moneda) })),
      el('div', { class: 'item-sub', text: [Panel.fmtFecha(g.fecha), g.categoria, g.suscripcion_id ? 'Suscripción' : null].filter(Boolean).join(' · ') }));
  }

  async function cargarGastos() {
    gastos = await Panel.get('/api/gastos', { desde: fDesde.value, hasta: fHasta.value, categoria: fCat.value.trim() });
    actualizarCategorias();
    const suma = {};
    for (const g of gastos) suma[g.moneda] = (suma[g.moneda] || 0) + Number(g.monto);
    const total = Object.entries(suma).map(([m, t]) => Panel.fmtMonto(t, m)).join(' + ');
    Panel.llenar(contGastos,
      gastos.length ? el('p', { class: 'item-sub', text: `Total del período: ${total}` }) : null,
      gastos.length ? el('div', { class: 'lista' }, gastos.map(itemGasto)) : Panel.vacio('No hay gastos en ese período.'));
  }

  Panel.acciones(
    Panel.boton('+ Suscripción', async () => { if (await Suscripciones.nuevo(categorias)) recargarTodo(); }),
    Panel.boton('+ Gasto', async () => { if (await Gastos.nuevo(categorias)) cargarGastos().catch(Panel.manejarError); }, 'primario'));

  for (const input of [fDesde, fHasta, fCat]) input.addEventListener('change', () => cargarGastos().catch(Panel.manejarError));
  document.getElementById('filtros-gastos').addEventListener('submit', (e) => e.preventDefault());
  recargarTodo();
})();
