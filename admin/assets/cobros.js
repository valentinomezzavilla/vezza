'use strict';

(() => {
  const { el } = Panel;
  const fCliente = document.getElementById('filtro-cliente');
  const params = new URLSearchParams(location.search);
  let tabs;

  const render = (estado, textoVacio) => async function pintar(panel) {
    const cobros = await Panel.get('/api/cobros', { estado, cliente_id: fCliente.value });
    const recargar = () => pintar(panel).catch(Panel.manejarError);
    Panel.llenar(panel,
      cobros.length ? el('p', { class: 'item-sub', text: `Total: ${Cobros.totales(cobros)}` }) : null,
      cobros.length ? el('div', { class: 'lista' }, cobros.map((c) => Cobros.item(c, recargar))) : Panel.vacio(textoVacio));
  };

  const stat = (label, valor) => el('div', { class: 'stat' },
    el('div', { class: 'stat-label', text: label }),
    el('div', { class: 'stat-valor', text: valor }));

  // Quién está al día, por vencer o vencido, calculado desde el próximo vencimiento de cada suscripción.
  async function pintarSuscripciones(panel) {
    const { resumen, filas, debito_disponible: debitoDisponible } = await Panel.get('/api/suscripciones-mensuales', { cliente_id: fCliente.value });
    const recargar = () => pintarSuscripciones(panel).catch(Panel.manejarError);
    Panel.llenar(panel,
      el('div', { class: 'stats' },
        stat('Ingreso mensual', resumen.ingreso_mensual),
        stat('Al día', String(resumen.al_dia)),
        stat('Por vencer', String(resumen.por_vencer)),
        stat('Vencidas', String(resumen.vencidas))),
      resumen.otras_monedas ? el('p', { class: 'item-sub', text: 'El ingreso mensual suma solo las suscripciones en pesos (ARS): hay otras en otra moneda.' }) : null,
      el('div', { class: 'toolbar' },
        Panel.boton('+ Nueva suscripción', async () => {
          if (await Suscripciones.nueva({ cliente_id: fCliente.value || undefined })) recargar();
        }, 'primario'),
        el('a', { class: 'btn', href: '/admin/planes', text: 'Planes' })),
      filas.length
        ? el('div', { class: 'lista' }, filas.map((s) => Suscripciones.item(s, recargar, debitoDisponible)))
        : Panel.vacio('Todavía no hay suscripciones mensuales.'));
  }

  const TABS = [
    { id: 'pendientes', label: 'Pendientes', render: render('pendiente', 'No hay cobros pendientes por vencer.') },
    { id: 'vencidos', label: 'Vencidos', render: render('vencido', 'No hay cobros vencidos.') },
    { id: 'pagados', label: 'Pagados', render: render('pagado', 'Todavía no hay cobros pagados.') },
    { id: 'todos', label: 'Todos', render: render('', 'Todavía no cargaste cobros.') },
    { id: 'suscripciones', label: 'Suscripciones mensuales', render: pintarSuscripciones },
  ];

  Panel.acciones(Panel.boton('+ Nuevo cobro', async () => {
    if (await Cobros.nuevo({ cliente_id: fCliente.value || undefined })) tabs.activar(tabs.actual());
  }, 'primario'));

  Panel.clientes().then((clientes) => {
    fCliente.append(...clientes.map((c) => el('option', { value: String(c.id), text: c.nombre })));
    fCliente.value = params.get('cliente_id') || '';
    tabs = Panel.tabs(document.getElementById('cobros'), TABS);
    fCliente.addEventListener('change', () => {
      history.replaceState(null, '', location.pathname + Panel.qs({ cliente_id: fCliente.value }) + location.hash);
      tabs.activar(tabs.actual());
    });
  }).catch(Panel.manejarError);
})();
