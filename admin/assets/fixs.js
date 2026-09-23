'use strict';

(() => {
  const { el } = Panel;
  const tablero = document.getElementById('tablero');
  const fCliente = document.getElementById('filtro-cliente');
  const params = new URLSearchParams(location.search);

  Panel.acciones(Panel.boton('+ Nuevo fix', async () => {
    if (await Fixs.nuevo({ cliente_id: fCliente.value || undefined })) cargar();
  }, 'primario'));

  async function mover(id, estado, antesDe) {
    try {
      await Panel.put(`/api/fixs/${id}`, { estado, antes_de: antesDe });
    } catch (err) {
      Panel.manejarError(err);
    }
    cargar();
  }

  async function cargar() {
    history.replaceState(null, '', location.pathname + Panel.qs({ cliente_id: fCliente.value }));
    try {
      const fixs = await Panel.get('/api/fixs', { cliente_id: fCliente.value });
      kanbanBoard(tablero, { columnas: Fixs.COLUMNAS, items: fixs, tarjeta: (f) => Fixs.tarjeta(f, cargar), alMover: mover });
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  Panel.clientes().then((clientes) => {
    fCliente.append(...clientes.map((c) => el('option', { value: String(c.id), text: c.nombre })));
    fCliente.value = params.get('cliente_id') || '';
    fCliente.addEventListener('change', cargar);
    return cargar();
  }).catch(Panel.manejarError);
})();
