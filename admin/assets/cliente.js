'use strict';

(() => {
  const { el } = Panel;
  const id = Number(document.body.dataset.clienteId);
  const ficha = document.getElementById('ficha');

  const dato = (label, valor, href) => el('div', {},
    el('div', { class: 'stat-label', text: label }),
    valor ? (href ? el('a', { href, text: valor }) : el('div', { text: valor })) : el('div', { class: 'item-sub', text: '—' }));

  async function cargarFicha() {
    const c = await Panel.get(`/api/clientes/${id}`);
    document.getElementById('titulo').textContent = c.nombre;
    document.title = `${c.nombre} · VEZZA Admin`;
    Panel.llenar(ficha,
      el('div', { class: 'item-top' },
        Panel.badgeEstado('estadoCliente', c.estado),
        Panel.boton('Editar', async () => {
          const r = await Clientes.editar(c);
          if (r?.eliminado) location.href = '/admin/clientes';
          else if (r) cargarFicha().catch(Panel.manejarError);
        }, 'chico')),
      el('div', { class: 'datos' },
        dato('Email', c.email, c.email ? `mailto:${c.email}` : null),
        dato('Teléfono', c.telefono, c.telefono ? `tel:${c.telefono.replace(/[^\d+]/g, '')}` : null),
        dato('Rubro', c.rubro),
        dato('Cliente desde', c.fecha_inicio ? Panel.fmtFecha(c.fecha_inicio, true) : null)));
  }

  async function cargarAcceso() {
    const [c, acceso] = await Promise.all([
      Panel.get(`/api/clientes/${id}`),
      Panel.get('/api/acceso-cliente', { cliente_id: id }),
    ]);
    Panel.llenar(document.getElementById('acceso'),
      Acceso.vista(id, c, acceso, () => cargarAcceso().catch(Panel.manejarError)));
  }

  async function cargarSuscripcion() {
    const { filas, debito_disponible: debitoDisponible } = await Panel.get('/api/suscripciones-mensuales', { cliente_id: id });
    const recargar = () => cargarSuscripcion().catch(Panel.manejarError);
    Panel.llenar(document.getElementById('suscripcion'),
      el('div', { class: 'item-top' },
        el('h2', { text: 'Suscripción' }),
        Panel.boton('+ Nueva suscripción', async () => {
          if (await Suscripciones.nueva({ cliente_id: String(id) })) recargar();
        }, 'chico')),
      filas.length
        ? el('div', { class: 'lista' }, filas.map((s) => Suscripciones.ficha(s, recargar, debitoDisponible)))
        : Panel.vacio('Este cliente no tiene una suscripción mensual.'));
  }

  async function cargarSistemas() {
    const sistemas = await Panel.get('/api/sistemas', { cliente_id: id });
    Panel.llenar(document.getElementById('sistemas'), Sistemas.vista(id, sistemas, () => cargarSistemas().catch(Panel.manejarError)));
  }

  function tabEntidad(modulo, url, textoNuevo, textoVacio, extra = () => null) {
    return async function render(panel) {
      const items = await Panel.get(url, { cliente_id: id });
      const recargar = () => render(panel).catch(Panel.manejarError);
      Panel.llenar(panel,
        el('div', { class: 'toolbar' },
          Panel.boton(textoNuevo, async () => { if (await modulo.nuevo({ cliente_id: String(id) })) recargar(); }, 'primario')),
        extra(items),
        items.length ? el('div', { class: 'lista' }, items.map((x) => modulo.item(x, recargar))) : Panel.vacio(textoVacio));
    };
  }

  async function renderBitacora(panel) {
    const notas = await Panel.get('/api/notas-cliente', { cliente_id: id });
    const texto = el('textarea', { class: 'filtro', rows: 3, placeholder: 'Escribí una nota…', 'aria-label': 'Nueva nota' });
    const agregar = el('button', { type: 'submit', class: 'btn btn-primario', text: 'Agregar nota' });
    const form = el('form', { class: 'card' }, texto, el('div', { class: 'item-acciones' }, agregar));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!texto.value.trim()) return;
      agregar.disabled = true;
      try {
        await Panel.post('/api/notas-cliente', { cliente_id: id, contenido: texto.value });
        await renderBitacora(panel);
      } catch (err) {
        Panel.manejarError(err);
      } finally {
        agregar.disabled = false;
      }
    });
    const items = notas.map((n) => el('article', { class: 'item' },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-sub', text: Panel.fmtFechaHora(n.created_at) }),
        Panel.boton('Editar', () => editarNota(n, panel), 'chico')),
      el('p', { class: 'nota-texto', text: n.contenido })));
    Panel.llenar(panel, form, el('div', { class: 'lista seccion' }, items.length ? items : Panel.vacio('Todavía no hay notas.')));
  }

  async function editarNota(n, panel) {
    const r = await Panel.modalForm({
      titulo: 'Editar nota',
      campos: [{ name: 'contenido', label: 'Nota', type: 'textarea', required: true }],
      valores: n,
      enviar: (d) => Panel.put(`/api/notas-cliente/${n.id}`, d),
      eliminar: () => Panel.del(`/api/notas-cliente/${n.id}`),
    });
    if (r) renderBitacora(panel).catch(Panel.manejarError);
  }

  async function renderComprobantes(panel) {
    const lista = await Panel.get('/api/comprobantes-pago', { cliente_id: id });
    const items = lista.map((c) => el('article', { class: 'item' },
      el('div', { class: 'item-top' },
        el('a', { class: 'item-titulo', href: `/admin/comprobante?n=${c.numero}`, target: '_blank', rel: 'noopener', text: `N° ${c.numero}` }),
        el('span', { class: 'item-titulo', text: Panel.fmtMonto(c.datos.monto, c.datos.moneda) })),
      el('div', { class: 'item-sub', text: `${Panel.fmtFecha(c.datos.fecha)} · ${c.datos.concepto}` }),
      el('div', { class: 'item-acciones' },
        c.anulado
          ? Panel.badge(`Anulado · ${c.motivo_anulacion ?? ''}`, 'danger')
          : Panel.badge(c.enviado_en ? `Enviado a ${c.destino}` : (c.error || 'Sin enviar'), c.enviado_en ? 'ok' : 'warn'))));
    Panel.llenar(panel,
      el('p', { class: 'item-sub', text: 'Cada pago registrado emite un comprobante (no válido como factura) y se le manda al cliente por mail.' }),
      items.length ? el('div', { class: 'lista seccion' }, items) : Panel.vacio('Todavía no hay comprobantes.'));
  }

  const totalCobros = (cobros) => (cobros.length ? el('p', { class: 'item-sub', text: `Total: ${Cobros.totales(cobros)}` }) : null);

  const TABS = [
    { id: 'procesos', label: 'Procesos', render: tabEntidad(Procesos, '/api/procesos', '+ Nuevo proceso', 'Este cliente todavía no tiene procesos.') },
    { id: 'cobros', label: 'Cobros', render: tabEntidad(Cobros, '/api/cobros', '+ Nuevo cobro', 'Este cliente todavía no tiene cobros.', totalCobros) },
    { id: 'comprobantes', label: 'Comprobantes', render: renderComprobantes },
    { id: 'fixs', label: 'Fixs', render: tabEntidad(Fixs, '/api/fixs', '+ Nuevo fix', 'Sin fixs reportados.') },
    { id: 'bitacora', label: 'Bitácora', render: renderBitacora },
    { id: 'eventos', label: 'Eventos', render: tabEntidad(Eventos, '/api/eventos', '+ Nuevo evento', 'Sin eventos con este cliente.') },
  ];

  cargarFicha()
    .then(() => Panel.tabs(document.getElementById('pestanas'), TABS))
    .catch(Panel.manejarError);
  cargarAcceso().catch(Panel.manejarError);
  cargarSuscripcion().catch(Panel.manejarError);
  cargarSistemas().catch(Panel.manejarError);
})();
