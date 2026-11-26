'use strict';

// Transferencias que los clientes informaron desde su portal y esperan que las confirmes. Confirmar registra el pago (con la fecha
// y el monto informados) y manda el comprobante; Rechazar pide un motivo y se lo avisa al cliente. La sección solo aparece si hay alguna.
const Informados = (() => {
  const { el } = Panel;

  const AVISO = {
    enviado: 'Listo: le mandé el comprobante.',
    no_enviado: 'Pago registrado, pero no salió el mail del comprobante: reenvialo desde el cobro.',
    sin_mail: 'Pago registrado. El cliente no tiene mail cargado: avisale por otro medio.',
  };
  const AVISO_RECHAZO = {
    enviado: 'Rechazada. Le avisé al cliente por mail.',
    no_enviado: 'Rechazada, pero no salió el mail: avisale por otro medio.',
    sin_mail: 'Rechazada. El cliente no tiene mail cargado: avisale por otro medio.',
  };

  function item(p, recargar, conCliente) {
    const accion = (fn) => async () => {
      try {
        if (await fn()) recargar();
      } catch (err) {
        Panel.manejarError(err);
      }
    };
    return el('article', { class: 'item' },
      el('div', { class: 'item-top' },
        conCliente
          ? el('a', { class: 'item-titulo', href: `/admin/clientes/${p.cliente_id}`, text: p.cliente_nombre })
          : el('span', { class: 'item-titulo', text: p.plan_nombre }),
        el('span', { class: 'item-titulo', text: Panel.fmtMonto(p.monto, p.moneda) })),
      el('div', { class: 'item-sub', text: `${conCliente ? `${p.plan_nombre} · ` : ''}Transferencia del ${Panel.fmtFecha(p.fecha, true)} · informada el ${Panel.fmtFechaHora(p.creado_en)}` }),
      p.nota ? el('p', { class: 'nota-texto', text: p.nota }) : null,
      el('div', { class: 'item-acciones' },
        p.tiene_archivo ? el('a', { class: 'btn btn-chico', href: `/api/pagos-informados/${p.id}/archivo`, target: '_blank', rel: 'noopener', text: 'Ver comprobante' }) : null,
        Panel.boton('Confirmar', accion(async () => {
          if (!(await Panel.confirmar(`Se registra el pago de ${Panel.fmtMonto(p.monto, p.moneda)} y se le manda el comprobante al cliente.`, 'Confirmar'))) return false;
          const r = await Panel.post(`/api/pagos-informados/${p.id}/confirmar`);
          Panel.toast(AVISO[r.aviso] ?? 'Pago registrado.');
          return true;
        }), 'chico'),
        Panel.boton('Rechazar', accion(async () => {
          const r = await Panel.modalForm({
            titulo: 'Rechazar transferencia',
            campos: [{ name: 'motivo', label: 'Motivo', type: 'textarea', required: true, ayuda: 'Se lo mando al cliente por mail. Por ejemplo: "El comprobante no corresponde a esta cuenta".' }],
            textoBoton: 'Rechazar',
            enviar: (d) => Panel.post(`/api/pagos-informados/${p.id}/rechazar`, d),
          });
          if (r) Panel.toast(AVISO_RECHAZO[r.aviso] ?? 'Rechazada.');
          return Boolean(r);
        }), 'chico')));
  }

  /** Llena `contenedor` con los avisos pendientes (de un cliente o de todos) y lo oculta si no hay ninguno. */
  async function pintar(contenedor, clienteId, alCambiar = () => {}) {
    const lista = await Panel.get('/api/pagos-informados', clienteId ? { cliente_id: clienteId } : {});
    contenedor.hidden = lista.length === 0;
    const recargar = () => {
      pintar(contenedor, clienteId, alCambiar).catch(Panel.manejarError);
      alCambiar();
    };
    Panel.llenar(contenedor,
      el('div', { class: 'item-top' },
        el('h2', { text: 'Transferencias informadas' }),
        Panel.badge(`${lista.length} para revisar`, 'warn')),
      el('p', { class: 'item-sub', text: 'Los clientes avisaron estas transferencias desde su portal. No se registra nada hasta que las confirmes.' }),
      el('div', { class: 'lista seccion' }, lista.map((p) => item(p, recargar, !clienteId))));
  }

  return { pintar };
})();
