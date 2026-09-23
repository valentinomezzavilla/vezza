'use strict';

(() => {
  const { el } = Panel;
  const cont = document.getElementById('dashboard');

  const seccion = (titulo, link, ...contenido) => el('section', { class: 'seccion' },
    el('div', { class: 'item-top' }, el('h2', { text: titulo }), link ? el('a', { href: link[0], text: link[1] }) : null),
    contenido);

  const stat = (label, valor, href) => el('a', { class: 'stat', href },
    el('div', { class: 'stat-label', text: label }),
    el('div', { class: 'stat-valor', text: String(valor) }));

  function tarjetaBalance(b) {
    return el('div', { class: 'stat' },
      el('div', { class: 'stat-label', text: `Balance del mes · ${b.moneda}` }),
      el('div', { class: `stat-valor${Number(b.balance) < 0 ? ' negativo' : ''}`, text: Panel.fmtMonto(b.balance, b.moneda) }),
      el('div', { class: 'item-sub', text: `Ingresos ${Panel.fmtMonto(b.ingresos, b.moneda)} · Gastos ${Panel.fmtMonto(b.gastos, b.moneda)}` }));
  }

  function cobros(d) {
    const totales = d.cobros_pendientes_totales.map((t) =>
      el('p', { class: 'item-sub', text: `${t.moneda}: vencido ${Panel.fmtMonto(t.vencido, t.moneda)} · por vencer ${Panel.fmtMonto(t.por_vencer, t.moneda)}` }));
    const items = d.cobros_pendientes.map((c) => el('a', {
      class: 'item', href: `/admin/cobros?cliente_id=${c.cliente_id}#${c.estado_efectivo === 'vencido' ? 'vencidos' : 'pendientes'}`,
    },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-titulo', text: Panel.fmtMonto(c.monto, c.moneda) }),
        Panel.badgeEstado('estadoCobro', c.estado_efectivo)),
      el('div', { class: 'item-sub', text: `${c.cliente_nombre} · vence ${Panel.fmtFecha(c.fecha_vencimiento)}` })));
    return seccion('Cobros pendientes', ['/admin/cobros', 'Ver todos'],
      totales, el('div', { class: 'lista' }, items.length ? items : Panel.vacio('No hay cobros pendientes.')));
  }

  function vencimientos(d) {
    const items = d.suscripciones_proximas.map((s) => {
      const dias = s.dias_restantes;
      let cuando = `en ${dias} d`;
      if (dias < 0) cuando = `atrasada ${-dias} d`;
      else if (dias === 0) cuando = 'hoy';
      return el('a', { class: 'item', href: '/admin/gastos' },
        el('div', { class: 'item-top' },
          el('span', { class: 'item-titulo', text: s.servicio }),
          Panel.badge(cuando, dias < 0 ? 'danger' : (dias <= 7 ? 'warn' : ''))),
        el('div', { class: 'item-sub', text: `${Panel.fmtMonto(s.monto, s.moneda)} · ${Panel.fmtFecha(s.fecha_proximo_cobro)}` }));
    });
    return seccion('Próximos vencimientos', ['/admin/gastos', 'Ver gastos'],
      el('div', { class: 'lista' }, items.length ? items : Panel.vacio('Nada vence en los próximos 14 días.')));
  }

  function procesos(d) {
    const bloques = d.procesos_por_cliente.map((c) => el('div', { class: 'item' },
      el('a', { class: 'item-titulo', href: `/admin/clientes/${c.cliente_id}`, text: c.cliente_nombre }),
      c.procesos.map((p) => el('div', { class: 'item-top' },
        el('a', { href: `/admin/procesos/${p.id}`, text: p.titulo }),
        Panel.badgeEstado('estadoProceso', p.estado)))));
    return seccion('Procesos activos por cliente', ['/admin/procesos', 'Ver tablero'],
      el('div', { class: 'lista' }, bloques.length ? bloques : Panel.vacio('No hay procesos activos.')));
  }

  function eventos(d) {
    const items = d.proximos_eventos.map((e) => el('a', { class: 'item', href: '/admin/agenda' },
      el('span', { class: 'item-titulo', text: e.titulo }),
      el('span', { class: 'item-sub', text: [Panel.fmtFechaHora(e.fecha_hora), e.cliente_nombre].filter(Boolean).join(' · ') })));
    return seccion('Próximos eventos', ['/admin/agenda', 'Ver agenda'],
      el('div', { class: 'lista' }, items.length ? items : Panel.vacio('No hay eventos agendados.')));
  }

  async function cargar() {
    const d = await Panel.get('/api/dashboard');
    const mesTexto = Panel.aFecha(`${d.mes}-01`).toLocaleDateString('es-AR', { month: 'long', year: 'numeric' });
    const activos = d.procesos_por_cliente.reduce((n, c) => n + c.procesos.length, 0);
    Panel.llenar(cont,
      el('p', { class: 'item-sub', text: `Resumen de ${mesTexto}` }),
      el('div', { class: 'stats' },
        d.balance_mes.length ? d.balance_mes.map(tarjetaBalance) : el('div', { class: 'stat' }, el('div', { class: 'stat-label', text: 'Todavía no hay ingresos ni gastos este mes.' }))),
      el('div', { class: 'stats' },
        stat('Procesos activos', activos, '/admin/procesos'),
        stat('Tareas abiertas', d.tareas_abiertas, '/admin/tareas'),
        stat('Fixs abiertos', d.fixs_abiertos, '/admin/fixs')),
      el('div', { class: 'grid-2' }, cobros(d), vencimientos(d), procesos(d), eventos(d)));
  }

  cargar().catch(Panel.manejarError);
})();
