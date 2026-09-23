'use strict';

const CATEGORIAS_BASE = ['Automatización', 'Diseño', 'Dominios', 'Hardware', 'Hosting', 'IA', 'Impuestos', 'Otros', 'Software'];

const Suscripciones = {
  campos(categorias) {
    return [
      { name: 'servicio', label: 'Servicio', required: true, placeholder: 'Claude, n8n, Hostinger…' },
      { name: 'categoria', label: 'Categoría', list: categorias },
      { name: 'monto', label: 'Monto', type: 'money', required: true },
      { name: 'moneda', label: 'Moneda', required: true, default: 'USD', maxlength: 3, list: ['ARS', 'USD'] },
      { name: 'frecuencia', label: 'Frecuencia', type: 'select', options: Panel.opciones(Panel.ETQ.frecuencia) },
      { name: 'fecha_proximo_cobro', label: 'Próximo cobro', type: 'date', required: true },
      { name: 'activa', label: 'Activa', type: 'checkbox', default: true },
    ];
  },
  nuevo(categorias) {
    return Panel.modalForm({ titulo: 'Nueva suscripción', campos: this.campos(categorias), enviar: (d) => Panel.post('/api/suscripciones', d) });
  },
  editar(s, categorias) {
    return Panel.modalForm({
      titulo: 'Editar suscripción', campos: this.campos(categorias), valores: s,
      enviar: (d) => Panel.put(`/api/suscripciones/${s.id}`, d),
      eliminar: () => Panel.del(`/api/suscripciones/${s.id}`),
    });
  },
  pagar(s) {
    return Panel.modalForm({
      titulo: `Registrar pago de ${s.servicio}`,
      textoBoton: 'Registrar pago',
      campos: [
        { name: 'fecha', label: 'Fecha del pago', type: 'date', required: true },
        { name: 'monto', label: `Monto (${s.moneda})`, type: 'money', required: true },
      ],
      valores: { fecha: s.fecha_proximo_cobro, monto: s.monto },
      enviar: (d) => Panel.post(`/api/suscripciones/${s.id}/pagar`, d),
    });
  },
};

const Gastos = {
  campos(categorias) {
    return [
      { name: 'concepto', label: 'Concepto', required: true, placeholder: 'Renovación dominio' },
      { name: 'categoria', label: 'Categoría', list: categorias },
      { name: 'monto', label: 'Monto', type: 'money', required: true },
      { name: 'moneda', label: 'Moneda', required: true, default: 'ARS', maxlength: 3, list: ['ARS', 'USD'] },
      { name: 'fecha', label: 'Fecha', type: 'date', required: true, default: Panel.hoy() },
    ];
  },
  nuevo(categorias) {
    return Panel.modalForm({ titulo: 'Nuevo gasto', campos: this.campos(categorias), enviar: (d) => Panel.post('/api/gastos', d) });
  },
  editar(g, categorias) {
    return Panel.modalForm({
      titulo: 'Editar gasto', campos: this.campos(categorias), valores: g,
      enviar: (d) => Panel.put(`/api/gastos/${g.id}`, d),
      eliminar: () => Panel.del(`/api/gastos/${g.id}`),
    });
  },
};
