'use strict';

const Clientes = {
  campos() {
    return [
      { name: 'nombre', label: 'Nombre', required: true },
      { name: 'email', label: 'Email', type: 'email' },
      { name: 'telefono', label: 'Teléfono', type: 'tel' },
      { name: 'rubro', label: 'Rubro', placeholder: 'Gastronomía, salud, retail…' },
      { name: 'estado', label: 'Estado', type: 'select', options: Panel.opciones(Panel.ETQ.estadoCliente) },
      { name: 'fecha_inicio', label: 'Fecha de inicio', type: 'date', default: Panel.hoy() },
    ];
  },
  async nuevo() {
    const r = await Panel.modalForm({ titulo: 'Nuevo cliente', campos: this.campos(), enviar: (d) => Panel.post('/api/clientes', d) });
    if (r) Panel.clientes(true);
    return r;
  },
  async editar(c) {
    const r = await Panel.modalForm({
      titulo: 'Editar cliente',
      campos: this.campos(),
      valores: c,
      enviar: (d) => Panel.put(`/api/clientes/${c.id}`, d),
      eliminar: () => Panel.del(`/api/clientes/${c.id}`),
    });
    if (r) Panel.clientes(true);
    return r;
  },
};
