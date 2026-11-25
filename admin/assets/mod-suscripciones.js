'use strict';

// Suscripciones mensuales de los clientes: la fila de la pestaña de Cobros, la sección de la ficha del cliente y todas las
// acciones (registrar pago, pasar a débito, ajustar precio, pausar, sincronizar, cancelar, reactivar). Los datos vienen de
// /api/suscripciones-mensuales y las acciones van a /api/suscripciones-cliente/{id}/{accion}.
const Suscripciones = (() => {
  const { el } = Panel;

  const MODALIDAD = { debito: 'Débito automático', transferencia: 'Transferencia' };
  const AVISO = {
    enviado: 'Se le mandó el mail al cliente.',
    no_enviado: 'No salió el mail al cliente: avisale por otro medio.',
    sin_mail: 'El cliente no tiene mail cargado: avisale por otro medio.',
    ya_enviado: '',
  };
  const DEBITO_EN_CURSO = ['pendiente', 'autorizada', 'pausada'];

  const sinVacios = (d) => Object.fromEntries(Object.entries(d).filter(([, v]) => v !== null));
  const pausada = (s) => Boolean(Number(s.pausada));
  const cancelada = (s) => s.estado === 'cancelada';
  const conDebito = (s) => DEBITO_EN_CURSO.includes(s.mp_preapproval_estado);

  function diasTexto(dias) {
    if (dias === null || dias === undefined) return '';
    if (dias < 0) return `venció hace ${-dias} ${dias === -1 ? 'día' : 'días'}`;
    if (dias === 0) return 'vence hoy';
    return `faltan ${dias} ${dias === 1 ? 'día' : 'días'}`;
  }

  function badges(s) {
    const lista = [];
    if (cancelada(s)) lista.push(Panel.badge('Cancelada'));
    else if (pausada(s)) lista.push(Panel.badge('Pausada', 'warn'));
    else lista.push(Panel.badgeEstado('estadoSuscripcion', s.cobro_estado));
    lista.push(Panel.badge(MODALIDAD[s.modalidad] ?? s.modalidad, s.modalidad === 'debito' ? 'accent' : ''));
    if (s.debito_pendiente) lista.push(Panel.badge('Débito pendiente de autorización', 'warn'));
    return lista;
  }

  function pagoHasta(s) {
    if (!s.fecha_proximo_cobro) return 'Sin fecha de próximo cobro';
    const dias = diasTexto(s.cobro_dias);
    return `Pago hasta ${Panel.fmtFecha(s.fecha_proximo_cobro, true)}${dias && !cancelada(s) ? ` · ${dias}` : ''}`;
  }

  function ultimoPago(s) {
    const p = s.ultimo_pago;
    if (!p) return 'Todavía no hay pagos registrados';
    return `Último pago: ${Panel.fmtMonto(p.monto, p.moneda)} el ${Panel.fmtFecha(p.fecha_pago, true)}${p.metodo_pago ? ` · ${p.metodo_pago}` : ''}`;
  }

  /** @returns {Array<[string, string]>} Las acciones que corresponden a esta suscripción, como [valor, texto]. */
  function acciones(s, debitoDisponible) {
    if (cancelada(s)) return [['reactivar', 'Reactivar'], ['editar', 'Editar fechas y plan']];
    // Una suscripción vencida con teléfono válido arranca con el aviso por WhatsApp: es lo primero que se hace.
    const lista = s.whatsapp_url ? [['whatsapp', 'Avisar por WhatsApp'], ['pagar', 'Registrar pago']] : [['pagar', 'Registrar pago']];
    if (!conDebito(s) && debitoDisponible) lista.push(['debito', 'Pasar a débito']);
    if (s.debito_pendiente && s.mp_init_point) lista.push(['link', 'Ver link de alta del débito']);
    lista.push(['precio', 'Ajustar precio']);
    lista.push(pausada(s) ? ['reanudar', 'Reanudar cobro'] : ['pausar', 'Pausar cobro']);
    if (s.mp_preapproval_id) lista.push(['sincronizar', 'Sincronizar con Mercado Pago']);
    lista.push(['editar', 'Editar fechas y plan'], ['cancelar', 'Cancelar suscripción']);
    return lista;
  }

  // ---- formularios ----

  async function pedirPago(s) {
    return Panel.modalForm({
      titulo: 'Registrar pago',
      campos: [
        { name: 'meses', label: 'Meses que cubre', type: 'number', default: 1, min: 1, max: 24 },
        { name: 'monto', label: 'Monto', type: 'money', placeholder: String(s.monto), ayuda: 'Si lo dejás vacío se usa el monto de la suscripción por la cantidad de meses.' },
        { name: 'metodo_pago', label: 'Medio de pago', default: 'Transferencia', list: ['Transferencia', 'Efectivo', 'Mercado Pago', 'Tarjeta'] },
        { name: 'nota', label: 'Nota (opcional)', maxlength: 200 },
      ],
      enviar: (d) => Panel.post(`/api/suscripciones-cliente/${s.id}/pagar`, sinVacios(d)),
      textoBoton: 'Registrar',
    });
  }

  function verLink(s) {
    return Panel.modalForm({
      titulo: 'Link de alta del débito',
      campos: [{
        name: 'link', label: 'Link para mandarle al cliente', soloLectura: true,
        ayuda: 'El cliente lo abre, elige su tarjeta y autoriza. La suscripción pasa a débito apenas Mercado Pago lo confirme (a veces tarda unos minutos: podés usar Sincronizar).',
      }],
      valores: { link: s.mp_init_point },
      enviar: async () => {
        try {
          await navigator.clipboard.writeText(s.mp_init_point);
          Panel.toast('Link copiado');
        } catch {
          Panel.toast('No pude copiarlo. Abrí la suscripción y probá de nuevo.', 'error');
        }
        return { copiado: true };
      },
      textoBoton: 'Copiar link',
    });
  }

  async function pedirPrecio(s) {
    return Panel.modalForm({
      titulo: 'Ajustar precio',
      campos: [{
        name: 'monto', label: `Monto nuevo (${s.moneda})`, type: 'money', required: true,
        ayuda: `Hoy paga ${Panel.fmtMonto(s.monto, s.moneda)}. Rige desde el próximo cobro y se le avisa al cliente por mail.${conDebito(s) ? ' También se actualiza en Mercado Pago.' : ''}`,
      }],
      valores: { monto: s.monto },
      enviar: (d) => Panel.post(`/api/suscripciones-cliente/${s.id}/precio`, d),
      textoBoton: 'Cambiar precio',
    });
  }

  async function pedirDebito(s) {
    return Panel.modalForm({
      titulo: 'Pasar a débito automático',
      campos: [{
        name: 'inicio', label: 'Primer cobro (opcional)', type: 'date',
        ayuda: 'Si la dejás vacía, Mercado Pago empieza en el próximo cobro. Si esa fecha ya pasó, cobra apenas el cliente autorice.',
      }],
      enviar: (d) => Panel.post(`/api/suscripciones-cliente/${s.id}/debito`, sinVacios(d)),
      textoBoton: 'Crear link de alta',
    });
  }

  async function editar(s) {
    const planes = await Panel.get('/api/planes');
    return Panel.modalForm({
      titulo: 'Editar suscripción',
      campos: [
        { name: 'plan_id', label: 'Plan', type: 'select', required: true, options: planes.filter((p) => p.moneda === s.moneda).map((p) => [String(p.id), p.nombre]), ayuda: 'Cambiar de plan no cambia el monto: para eso usá Ajustar precio.' },
        { name: 'fecha_inicio', label: 'Fecha de inicio', type: 'date', required: true },
        { name: 'fecha_proximo_cobro', label: 'Pago hasta / próximo cobro', type: 'date', required: true },
      ],
      valores: s,
      enviar: (d) => Panel.put(`/api/suscripciones-cliente/${s.id}`, d),
      eliminar: () => Panel.del(`/api/suscripciones-cliente/${s.id}`),
    });
  }

  async function nueva(valores = {}) {
    const [clientes, planes] = await Promise.all([Panel.clientes(), Panel.get('/api/planes')]);
    return Panel.modalForm({
      titulo: 'Nueva suscripción',
      campos: [
        { name: 'cliente_id', label: 'Cliente', type: 'select', required: true, vacio: 'Elegí un cliente', options: Panel.opcionesClientes(clientes) },
        { name: 'plan_id', label: 'Plan', type: 'select', required: true, vacio: 'Elegí un plan', options: planes.filter((p) => Number(p.activo)).map((p) => [String(p.id), `${p.nombre} · ${Panel.fmtMonto(p.monto, p.moneda)}`]) },
        { name: 'monto', label: 'Monto (opcional)', type: 'money', ayuda: 'Si lo dejás vacío se usa el del plan.' },
        { name: 'fecha_inicio', label: 'Fecha de inicio', type: 'date', default: Panel.hoy() },
        { name: 'fecha_proximo_cobro', label: 'Próximo cobro (pago hasta)', type: 'date', required: true, default: Panel.hoy(), ayuda: 'Cada pago que registres, o que cobre Mercado Pago, la adelanta un mes.' },
      ],
      valores,
      enviar: (d) => Panel.post('/api/suscripciones-cliente', sinVacios(d)),
    });
  }

  // ---- acciones ----

  /** Corre una acción y, si se hizo algo, recarga. Los errores salen como aviso: nunca rompen la pantalla. */
  async function ejecutar(accion, s, recargar, debitoDisponible = false) {
    const url = (a) => `/api/suscripciones-cliente/${s.id}/${a}`;
    const hecho = (mensaje) => { Panel.toast(mensaje); recargar(); };
    const conAviso = (mensaje, r) => hecho([mensaje, AVISO[r.aviso]].filter(Boolean).join(' '));
    try {
      switch (accion) {
        case 'pagar': {
          const r = await pedirPago(s);
          if (r) hecho(`Pago registrado. Pago hasta ${Panel.fmtFecha(r.suscripcion.fecha_proximo_cobro, true)}.`);
          break;
        }
        case 'whatsapp':
          window.open(s.whatsapp_url, '_blank', 'noopener');
          break;
        case 'debito': {
          const r = await pedirDebito(s);
          if (r) {
            await verLink(r);
            recargar();
          }
          break;
        }
        case 'link':
          await verLink(s);
          break;
        case 'precio': {
          const r = await pedirPrecio(s);
          if (r) conAviso('Precio actualizado.', r);
          break;
        }
        case 'pausar':
          await Panel.post(url('pausar'));
          hecho('Cobro pausado');
          break;
        case 'reanudar':
          await Panel.post(url('reanudar'));
          hecho('Cobro reanudado');
          break;
        case 'sincronizar': {
          const r = await Panel.post(url('sincronizar'));
          hecho(r.pagos ? `Sincronizado: ${r.pagos} ${r.pagos === 1 ? 'pago nuevo' : 'pagos nuevos'}.` : 'Sincronizado: no había pagos nuevos.');
          break;
        }
        case 'cancelar': {
          const texto = conDebito(s)
            ? '¿Cancelar la suscripción? Se cancela el débito en Mercado Pago y se le avisa al cliente por mail hasta cuándo sigue activo lo que ya pagó.'
            : '¿Cancelar la suscripción? Se le avisa al cliente por mail hasta cuándo sigue activo lo que ya pagó.';
          if (!(await Panel.confirmar(texto, 'Cancelar suscripción'))) break;
          conAviso('Suscripción cancelada.', await Panel.post(url('cancelar')));
          break;
        }
        case 'reactivar': {
          const r = await Panel.post(url('reactivar'));
          if (r.debito_pendiente || r.mp_preapproval_estado === 'pendiente') await verLink(r);
          hecho('Suscripción reactivada.');
          break;
        }
        case 'editar': {
          const r = await editar(s);
          if (r) recargar();
          break;
        }
        default:
          break;
      }
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  // ---- vistas ----

  /** Una suscripción como fila de la pestaña de Cobros: botón principal visible y el resto en "Más acciones…". */
  function item(s, recargar, debitoDisponible = false) {
    const todas = acciones(s, debitoDisponible);
    const [principal, ...resto] = todas;
    const selector = el('select', { class: 'filtro', 'aria-label': `Más acciones para ${s.cliente_nombre}` },
      el('option', { value: '', text: 'Más acciones…' }),
      resto.map(([valor, texto]) => el('option', { value: valor, text: texto })));
    selector.addEventListener('change', () => {
      const valor = selector.value;
      selector.value = '';
      if (valor) ejecutar(valor, s, recargar, debitoDisponible);
    });
    return el('div', { class: 'item' },
      el('div', { class: 'item-top' },
        el('a', { class: 'item-titulo', href: `/admin/clientes/${s.cliente_id}`, text: s.cliente_nombre }),
        el('span', { class: 'item-titulo', text: Panel.fmtMonto(s.monto, s.moneda) })),
      el('div', { class: 'item-sub', text: s.plan_nombre }),
      el('div', { class: 'item-acciones' }, badges(s)),
      el('div', { class: 'item-sub', text: pagoHasta(s) }),
      el('div', { class: 'item-sub', text: ultimoPago(s) }),
      el('div', { class: 'item-acciones' },
        Panel.boton(principal[1], () => ejecutar(principal[0], s, recargar, debitoDisponible), 'chico'),
        resto.length ? selector : null));
  }

  return { acciones, ejecutar, item, nueva, badges, pagoHasta, ultimoPago, verLink };
})();
