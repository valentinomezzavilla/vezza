'use strict';

const Eventos = {
  campos(clientes) {
    return [
      { name: 'titulo', label: 'Título', required: true, placeholder: 'Llamada con…' },
      { name: 'tipo', label: 'Tipo', type: 'select', options: Panel.opciones(Panel.ETQ.tipoEvento) },
      { name: 'fecha_hora', label: 'Fecha y hora', type: 'datetime-local', required: true },
      { name: 'duracion_min', label: 'Duración (minutos)', type: 'number', min: 1, max: 1440 },
      { name: 'cliente_id', label: 'Cliente', type: 'select', vacio: 'Sin cliente', options: Panel.opcionesClientes(clientes) },
      { name: 'descripcion', label: 'Notas', type: 'textarea' },
    ];
  },
  async nuevo(valores = {}) {
    const clientes = await Panel.clientes();
    return Panel.modalForm({ titulo: 'Nuevo evento', campos: this.campos(clientes), valores, enviar: (d) => Panel.post('/api/eventos', d) });
  },
  async editar(e) {
    const clientes = await Panel.clientes();
    return Panel.modalForm({
      titulo: 'Editar evento', campos: this.campos(clientes), valores: e,
      enviar: (d) => Panel.put(`/api/eventos/${e.id}`, d),
      eliminar: () => Panel.del(`/api/eventos/${e.id}`),
    });
  },
  item(e, recargar) {
    const { el } = Panel;
    const detalle = [Panel.fmtFechaHora(e.fecha_hora), e.duracion_min ? `${e.duracion_min} min` : null, e.cliente_nombre].filter(Boolean).join(' · ');
    return el('button', { type: 'button', class: 'item', onclick: async () => { if (await Eventos.editar(e)) recargar(); } },
      el('div', { class: 'item-top' }, el('span', { class: 'item-titulo', text: e.titulo }), Panel.badge(Panel.ETQ.tipoEvento[e.tipo])),
      el('div', { class: 'item-sub', text: detalle }));
  },
};
