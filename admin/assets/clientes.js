'use strict';

(() => {
  const { el } = Panel;
  const lista = document.getElementById('lista');
  const buscar = document.getElementById('buscar');
  const estado = document.getElementById('filtro-estado');
  let temporizador;

  Panel.acciones(Panel.boton('+ Nuevo cliente', async () => {
    const c = await Clientes.nuevo();
    if (c) location.href = `/admin/clientes/${c.id}`;
  }, 'primario'));

  function item(c) {
    const contacto = [c.rubro, c.email, c.telefono].filter(Boolean).join(' · ');
    return el('a', { class: 'item', href: `/admin/clientes/${c.id}` },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-titulo', text: c.nombre }),
        Panel.badgeEstado('estadoCliente', c.estado)),
      el('div', { class: 'item-sub', text: contacto || 'Sin datos de contacto' }),
      el('div', { class: 'meta' },
        Panel.badge(`${c.procesos_activos} ${c.procesos_activos === 1 ? 'proceso activo' : 'procesos activos'}`, c.procesos_activos ? 'accent' : ''),
        c.fixs_abiertos ? Panel.badge(`${c.fixs_abiertos} ${c.fixs_abiertos === 1 ? 'fix abierto' : 'fixs abiertos'}`, 'warn') : null));
  }

  async function cargar() {
    try {
      const clientes = await Panel.get('/api/clientes', { q: buscar.value.trim(), estado: estado.value });
      Panel.llenar(lista, clientes.length ? clientes.map(item) : Panel.vacio('No hay clientes con ese filtro.'));
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  buscar.addEventListener('input', () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(cargar, 250);
  });
  estado.addEventListener('change', cargar);
  cargar();
})();
