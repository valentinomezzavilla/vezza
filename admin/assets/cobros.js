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

  const TABS = [
    { id: 'pendientes', label: 'Pendientes', render: render('pendiente', 'No hay cobros pendientes por vencer.') },
    { id: 'vencidos', label: 'Vencidos', render: render('vencido', 'No hay cobros vencidos.') },
    { id: 'pagados', label: 'Pagados', render: render('pagado', 'Todavía no hay cobros pagados.') },
    { id: 'todos', label: 'Todos', render: render('', 'Todavía no cargaste cobros.') },
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
