'use strict';

const Cobros = {
  campos(clientes, procesos) {
    return [
      { name: 'cliente_id', label: 'Cliente', type: 'select', required: true, vacio: 'Elegí un cliente', options: Panel.opcionesClientes(clientes) },
      { name: 'proceso_id', label: 'Proceso (opcional)', type: 'select', vacio: 'Sin proceso', filtro: 'cliente_id', options: procesos.map((p) => [String(p.id), p.titulo, p.cliente_id]) },
      { name: 'concepto', label: 'Concepto', placeholder: 'Anticipo 50%' },
      { name: 'monto', label: 'Monto', type: 'money', required: true, placeholder: '150000' },
      { name: 'moneda', label: 'Moneda', required: true, default: 'ARS', maxlength: 3, list: ['ARS', 'USD'] },
      { name: 'fecha_vencimiento', label: 'Vence', type: 'date', required: true, default: Panel.hoy() },
      { name: 'estado', label: 'Estado', type: 'select', options: [['pendiente', 'Pendiente'], ['pagado', 'Pagado']] },
      { name: 'fecha_pago', label: 'Fecha de pago', type: 'date', ayuda: 'Si lo marcás pagado y la dejás vacía, se usa la fecha de hoy.' },
      { name: 'metodo_pago', label: 'Método de pago', list: ['Transferencia', 'Efectivo', 'Mercado Pago', 'PayPal', 'Tarjeta'] },
      { name: 'comprobante_url', label: 'Link al comprobante', type: 'url', placeholder: 'https://…' },
    ];
  },

  async datos() {
    const [clientes, procesos] = await Promise.all([Panel.clientes(), Panel.get('/api/procesos')]);
    return { clientes, procesos };
  },

  async nuevo(valores = {}) {
    const { clientes, procesos } = await this.datos();
    return Panel.modalForm({ titulo: 'Nuevo cobro', campos: this.campos(clientes, procesos), valores, enviar: (d) => Panel.post('/api/cobros', d) });
  },

  async editar(c) {
    const { clientes, procesos } = await this.datos();
    return Panel.modalForm({
      titulo: 'Editar cobro', campos: this.campos(clientes, procesos), valores: c,
      enviar: (d) => Panel.put(`/api/cobros/${c.id}`, d),
      eliminar: () => Panel.del(`/api/cobros/${c.id}`),
    });
  },

  subirComprobante(c, alTerminar) {
    const input = Panel.el('input', { type: 'file', accept: 'application/pdf,image/jpeg,image/png,image/webp' });
    input.addEventListener('change', async () => {
      const archivo = input.files[0];
      if (!archivo) return;
      if (archivo.size > 5 * 1024 * 1024) {
        Panel.toast('El archivo supera los 5 MB', 'error');
        return;
      }
      const fd = new FormData();
      fd.append('archivo', archivo);
      try {
        await Panel.api('POST', `/api/comprobante/${c.id}`, fd);
        Panel.toast('Comprobante subido');
        alTerminar();
      } catch (err) {
        Panel.manejarError(err);
      }
    });
    input.click();
  },

  async quitarComprobante(c, alTerminar) {
    if (!(await Panel.confirmar('¿Quitar el archivo del comprobante?', 'Quitar'))) return;
    try {
      await Panel.del(`/api/comprobante/${c.id}`);
      alTerminar();
    } catch (err) {
      Panel.manejarError(err);
    }
  },

  item(c, recargar) {
    const { el } = Panel;
    const acciones = [];
    if (c.estado === 'pendiente') {
      acciones.push(Panel.boton('Marcar pagado', async () => {
        try {
          await Panel.put(`/api/cobros/${c.id}`, { estado: 'pagado' });
          Panel.toast('Cobro marcado como pagado');
          recargar();
        } catch (err) {
          Panel.manejarError(err);
        }
      }, 'chico'));
    }
    acciones.push(Panel.boton('Editar', async () => { if (await Cobros.editar(c)) recargar(); }, 'chico'));
    acciones.push(Panel.boton(c.tiene_archivo ? 'Reemplazar archivo' : 'Subir archivo', () => Cobros.subirComprobante(c, recargar), 'chico'));
    if (c.tiene_archivo) {
      acciones.push(el('a', { class: 'btn btn-chico', href: `/api/comprobante/${c.id}`, target: '_blank', rel: 'noopener', text: 'Ver archivo' }));
      acciones.push(Panel.boton('Quitar archivo', () => Cobros.quitarComprobante(c, recargar), 'chico'));
    }
    if (c.comprobante_url) {
      acciones.push(el('a', { class: 'btn btn-chico', href: c.comprobante_url, target: '_blank', rel: 'noopener noreferrer', text: 'Ver link' }));
    }
    const fecha = c.estado === 'pagado' ? `Pagado el ${Panel.fmtFecha(c.fecha_pago)}` : `Vence ${Panel.fmtFecha(c.fecha_vencimiento)}`;
    return el('div', { class: 'item' },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-titulo', text: Panel.fmtMonto(c.monto, c.moneda) }),
        Panel.badgeEstado('estadoCobro', c.estado_efectivo)),
      el('div', { class: 'item-sub', text: [c.cliente_nombre, c.proceso_titulo, c.concepto].filter(Boolean).join(' · ') }),
      el('div', { class: 'item-sub', text: [fecha, c.metodo_pago].filter(Boolean).join(' · ') }),
      el('div', { class: 'item-acciones' }, acciones));
  },

  totales(cobros) {
    const suma = {};
    for (const c of cobros) suma[c.moneda] = (suma[c.moneda] || 0) + Number(c.monto);
    return Object.entries(suma).map(([m, t]) => Panel.fmtMonto(t, m)).join(' + ');
  },
};
