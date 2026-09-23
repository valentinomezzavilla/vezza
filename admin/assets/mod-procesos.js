'use strict';

const Procesos = {
  COLUMNAS: Object.entries(Panel.ETQ.estadoProceso),

  campos(clientes) {
    return [
      { name: 'cliente_id', label: 'Cliente', type: 'select', required: true, vacio: 'Elegí un cliente', options: Panel.opcionesClientes(clientes) },
      { name: 'titulo', label: 'Título', required: true, placeholder: 'Landing page' },
      { name: 'descripcion', label: 'Descripción', type: 'textarea' },
      { name: 'estado', label: 'Estado', type: 'select', options: Panel.opciones(Panel.ETQ.estadoProceso) },
      { name: 'prioridad', label: 'Prioridad', type: 'select', options: Panel.opciones(Panel.ETQ.prioridad), default: 'media' },
      { name: 'fecha_inicio', label: 'Fecha de inicio', type: 'date', default: Panel.hoy() },
      { name: 'fecha_entrega_estimada', label: 'Entrega estimada', type: 'date' },
    ];
  },

  async nuevo(valores = {}) {
    const clientes = await Panel.clientes();
    return Panel.modalForm({ titulo: 'Nuevo proceso', campos: this.campos(clientes), valores, enviar: (d) => Panel.post('/api/procesos', d) });
  },

  async editar(p) {
    const clientes = await Panel.clientes();
    const campos = this.campos(clientes).map((c) => (c.name === 'cliente_id' ? { ...c, soloLectura: true } : c));
    return Panel.modalForm({
      titulo: 'Editar proceso', campos, valores: p,
      enviar: (d) => Panel.put(`/api/procesos/${p.id}`, d),
      eliminar: () => Panel.del(`/api/procesos/${p.id}`),
    });
  },

  selectorEstado(p, alCambiar) {
    const s = Panel.el('select', { class: 'filtro', 'aria-label': `Estado de ${p.titulo}` },
      this.COLUMNAS.map(([v, l]) => Panel.el('option', { value: v, text: l })));
    s.value = p.estado;
    s.addEventListener('change', async () => {
      try {
        await Panel.put(`/api/procesos/${p.id}`, { estado: s.value });
        alCambiar();
      } catch (err) {
        s.value = p.estado;
        Panel.manejarError(err);
      }
    });
    return s;
  },

  resumen(p) {
    const abierto = p.estado !== 'entregado';
    const dias = p.fecha_entrega_estimada && abierto ? Panel.diasHasta(p.fecha_entrega_estimada) : null;
    let variante = '';
    if (dias !== null && dias < 0) variante = 'danger';
    else if (dias !== null && dias <= 7) variante = 'warn';
    return Panel.el('div', { class: 'meta' },
      p.prioridad !== 'media' ? Panel.badgeEstado('prioridad', p.prioridad) : null,
      p.subtareas_total ? Panel.badge(`${p.subtareas_hechas}/${p.subtareas_total}`, p.subtareas_hechas === p.subtareas_total ? 'ok' : '') : null,
      p.fecha_entrega_estimada ? Panel.badge(`Entrega ${Panel.fmtFecha(p.fecha_entrega_estimada)}`, variante) : null);
  },

  item(p, recargar) {
    const { el } = Panel;
    return el('div', { class: 'item' },
      el('div', { class: 'item-top' },
        el('a', { class: 'item-titulo', href: `/admin/procesos/${p.id}`, text: p.titulo }),
        Panel.badgeEstado('estadoProceso', p.estado)),
      el('div', { class: 'item-sub', text: p.cliente_nombre }),
      this.resumen(p),
      el('div', { class: 'item-acciones' }, this.selectorEstado(p, recargar)));
  },
};
