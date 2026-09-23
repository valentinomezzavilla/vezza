'use strict';

const Fixs = {
  COLUMNAS: Object.entries(Panel.ETQ.estadoFix),

  campos(clientes, procesos) {
    return [
      { name: 'cliente_id', label: 'Cliente', type: 'select', required: true, vacio: 'Elegí un cliente', options: Panel.opcionesClientes(clientes) },
      { name: 'proceso_id', label: 'Proceso entregado (opcional)', type: 'select', vacio: 'Sin proceso', filtro: 'cliente_id', options: procesos.map((p) => [String(p.id), p.titulo, p.cliente_id]) },
      { name: 'titulo', label: 'Qué falla', required: true, placeholder: 'El formulario no envía en Safari' },
      { name: 'descripcion', label: 'Detalle', type: 'textarea' },
      { name: 'estado', label: 'Estado', type: 'select', options: Panel.opciones(Panel.ETQ.estadoFix) },
      { name: 'prioridad', label: 'Prioridad', type: 'select', options: Panel.opciones(Panel.ETQ.prioridad), default: 'media' },
      { name: 'fecha_reportado', label: 'Reportado el', type: 'date', default: Panel.hoy() },
    ];
  },

  async datos() {
    const [clientes, procesos] = await Promise.all([Panel.clientes(), Panel.get('/api/procesos')]);
    return { clientes, procesos };
  },

  async nuevo(valores = {}) {
    const { clientes, procesos } = await this.datos();
    return Panel.modalForm({ titulo: 'Nuevo fix', campos: this.campos(clientes, procesos), valores, enviar: (d) => Panel.post('/api/fixs', d) });
  },

  async editar(f) {
    const { clientes, procesos } = await this.datos();
    return Panel.modalForm({
      titulo: 'Editar fix', campos: this.campos(clientes, procesos), valores: f,
      enviar: (d) => Panel.put(`/api/fixs/${f.id}`, d),
      eliminar: () => Panel.del(`/api/fixs/${f.id}`),
    });
  },

  selectorEstado(f, alCambiar) {
    const s = Panel.el('select', { class: 'filtro', 'aria-label': `Estado de ${f.titulo}` },
      this.COLUMNAS.map(([v, l]) => Panel.el('option', { value: v, text: l })));
    s.value = f.estado;
    s.addEventListener('change', async () => {
      try {
        await Panel.put(`/api/fixs/${f.id}`, { estado: s.value });
        alCambiar();
      } catch (err) {
        s.value = f.estado;
        Panel.manejarError(err);
      }
    });
    return s;
  },

  resumen(f) {
    return Panel.el('div', { class: 'meta' },
      f.prioridad !== 'media' ? Panel.badgeEstado('prioridad', f.prioridad) : null,
      Panel.badge(`Reportado ${Panel.fmtFecha(f.fecha_reportado)}`),
      f.fecha_resuelto ? Panel.badge(`Resuelto ${Panel.fmtFecha(f.fecha_resuelto)}`, 'ok') : null);
  },

  tarjeta(f, recargar) {
    const { el } = Panel;
    return [
      el('button', { type: 'button', class: 'boton-texto item-titulo', text: f.titulo, onclick: async () => { if (await Fixs.editar(f)) recargar(); } }),
      el('div', { class: 'item-sub', text: [f.cliente_nombre, f.proceso_titulo].filter(Boolean).join(' · ') }),
      this.resumen(f),
      this.selectorEstado(f, recargar),
    ];
  },

  item(f, recargar) {
    return Panel.el('div', { class: 'item' }, this.tarjeta(f, recargar));
  },
};
