'use strict';

(() => {
  const { el } = Panel;
  const contenedor = document.getElementById('planes');

  const campos = [
    { name: 'nombre', label: 'Nombre', required: true, placeholder: 'Mantenimiento web' },
    { name: 'descripcion', label: 'Descripción (opcional)', type: 'textarea' },
    { name: 'monto', label: 'Monto', type: 'money', required: true, placeholder: '25000', ayuda: 'Es el precio con el que arrancan las suscripciones nuevas. Cada suscripción puede tener el suyo.' },
    { name: 'moneda', label: 'Moneda', required: true, default: 'ARS', maxlength: 3, list: ['ARS', 'USD'], ayuda: 'El débito automático solo funciona en ARS.' },
    { name: 'frecuencia', label: 'Frecuencia', type: 'select', options: Panel.opciones(Panel.ETQ.frecuencia), default: 'mensual' },
    { name: 'orden', label: 'Orden', type: 'number', default: 0, ayuda: 'Los planes se listan de menor a mayor.' },
    { name: 'activo', label: 'Se ofrece al crear suscripciones', type: 'checkbox', default: true },
  ];

  const nuevo = () => Panel.modalForm({
    titulo: 'Nuevo plan', campos, enviar: (d) => Panel.post('/api/planes', d),
  });

  const editar = (p) => Panel.modalForm({
    titulo: 'Editar plan', campos, valores: p,
    enviar: (d) => Panel.put(`/api/planes/${p.id}`, d),
    eliminar: () => Panel.del(`/api/planes/${p.id}`),
  });

  function item(p, recargar) {
    const activo = Boolean(Number(p.activo));
    return el('div', { class: 'item' },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-titulo', text: p.nombre }),
        Panel.badge(activo ? 'Activo' : 'Inactivo', activo ? 'ok' : '')),
      el('div', { class: 'item-sub', text: `${Panel.fmtMonto(p.monto, p.moneda)} · ${Panel.ETQ.frecuencia[p.frecuencia] ?? p.frecuencia}` }),
      p.descripcion ? el('div', { class: 'item-sub', text: p.descripcion }) : null,
      el('div', { class: 'item-acciones' },
        Panel.boton('Editar', async () => { if (await editar(p)) recargar(); }, 'chico')));
  }

  async function cargar() {
    const planes = await Panel.get('/api/planes');
    Panel.llenar(contenedor,
      planes.length
        ? el('div', { class: 'lista' }, planes.map((p) => item(p, () => cargar().catch(Panel.manejarError))))
        : Panel.vacio('Todavía no cargaste planes.'));
  }

  Panel.acciones(
    el('a', { class: 'btn', href: '/admin/cobros#suscripciones', text: 'Volver a Cobros' }),
    Panel.boton('+ Nuevo plan', async () => { if (await nuevo()) cargar().catch(Panel.manejarError); }, 'primario'));
  cargar().catch(Panel.manejarError);
})();
