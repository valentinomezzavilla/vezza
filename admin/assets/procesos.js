'use strict';

(() => {
  const { el } = Panel;
  const contenido = document.getElementById('contenido');
  const fCliente = document.getElementById('filtro-cliente');
  const fEstado = document.getElementById('filtro-estado');
  const bKanban = document.getElementById('vista-kanban');
  const bLista = document.getElementById('vista-lista');
  const params = new URLSearchParams(location.search);
  let vista = params.get('vista') === 'lista' ? 'lista' : 'kanban';

  Panel.acciones(Panel.boton('+ Nuevo proceso', async () => {
    if (await Procesos.nuevo({ cliente_id: fCliente.value || undefined })) cargar();
  }, 'primario'));

  function marcarVista() {
    bKanban.setAttribute('aria-pressed', String(vista === 'kanban'));
    bLista.setAttribute('aria-pressed', String(vista === 'lista'));
  }

  function sincronizarUrl() {
    const q = Panel.qs({ cliente_id: fCliente.value, estado: fEstado.value, vista: vista === 'lista' ? 'lista' : '' });
    history.replaceState(null, '', location.pathname + q);
  }

  async function mover(id, estado, antesDe) {
    try {
      await Panel.put(`/api/procesos/${id}`, { estado, antes_de: antesDe });
    } catch (err) {
      Panel.manejarError(err);
    }
    cargar();
  }

  async function cargar() {
    sincronizarUrl();
    try {
      const procesos = await Panel.get('/api/procesos', { cliente_id: fCliente.value, estado: fEstado.value });
      if (vista === 'kanban') {
        kanbanBoard(contenido, {
          columnas: Procesos.COLUMNAS.filter(([k]) => !fEstado.value || k === fEstado.value),
          items: procesos,
          tarjeta: (p) => [
            el('a', { class: 'item-titulo', href: `/admin/procesos/${p.id}`, text: p.titulo }),
            el('div', { class: 'item-sub', text: p.cliente_nombre }),
            Procesos.resumen(p),
            Procesos.selectorEstado(p, cargar),
          ],
          alMover: mover,
        });
      } else {
        Panel.llenar(contenido, procesos.length
          ? el('div', { class: 'lista' }, procesos.map((p) => Procesos.item(p, cargar)))
          : Panel.vacio('No hay procesos con ese filtro.'));
      }
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  async function iniciar() {
    const clientes = await Panel.clientes();
    fCliente.append(...clientes.map((c) => el('option', { value: String(c.id), text: c.nombre })));
    fCliente.value = params.get('cliente_id') || '';
    fEstado.value = params.get('estado') || '';
    marcarVista();
    await cargar();
  }

  fCliente.addEventListener('change', cargar);
  fEstado.addEventListener('change', cargar);
  bKanban.addEventListener('click', () => { vista = 'kanban'; marcarVista(); cargar(); });
  bLista.addEventListener('click', () => { vista = 'lista'; marcarVista(); cargar(); });
  iniciar().catch(Panel.manejarError);
})();
