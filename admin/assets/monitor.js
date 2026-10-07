'use strict';

(() => {
  const { el } = Panel;
  const contResumen = document.getElementById('resumen');
  const contServicios = document.getElementById('servicios');
  const contEventos = document.getElementById('eventos');
  const REFRESCO_MS = 60000;

  let servicios = [];
  let chequeando = false;

  const ESTADO = { ok: ['Operativo', 'ok'], caido: ['Caído', 'danger'], desconocido: ['Sin datos', ''] };

  function hace(seg) {
    if (seg === null || seg === undefined) return 'nunca';
    if (seg < 60) return 'hace instantes';
    if (seg < 3600) return `hace ${Math.floor(seg / 60)} min`;
    if (seg < 86400) return `hace ${Math.floor(seg / 3600)} h`;
    return `hace ${Math.floor(seg / 86400)} d`;
  }

  function duracion(seg) {
    if (seg < 60) return 'menos de 1 min';
    if (seg < 3600) return `${Math.floor(seg / 60)} min`;
    if (seg < 86400) {
      const h = Math.floor(seg / 3600);
      const m = Math.floor((seg % 3600) / 60);
      return m ? `${h} h ${m} min` : `${h} h`;
    }
    const d = Math.floor(seg / 86400);
    const h = Math.floor((seg % 86400) / 3600);
    return h ? `${d} d ${h} h` : `${d} d`;
  }

  const porcentaje = (n) => `${Number(n).toLocaleString('es-AR', { maximumFractionDigits: 2 })} %`;
  const destinoTexto = (s) => (s.tipo === 'http' ? s.destino : `${s.destino}:${s.puerto}`);
  const grupos = () => [...new Set(servicios.map((s) => s.grupo).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));

  function resumen() {
    const activos = servicios.filter((s) => s.activo);
    const caidos = activos.filter((s) => s.estado === 'caido');
    const sinDatos = activos.filter((s) => s.estado === 'desconocido');
    let texto;
    let clase = 'monitor-resumen';
    if (!servicios.length) {
      texto = 'Todavía no cargaste servicios para monitorear.';
    } else if (caidos.length) {
      clase += ' caido';
      texto = caidos.length === 1 ? `1 servicio caído: ${caidos[0].nombre}` : `${caidos.length} servicios caídos`;
    } else if (!activos.length || sinDatos.length === activos.length) {
      texto = 'Esperando el primer chequeo.';
    } else {
      clase += ' ok';
      texto = `Todo operativo · ${activos.length - sinDatos.length} de ${activos.length} servicios chequeados`;
    }
    contResumen.className = clase;
    contResumen.textContent = texto;
  }

  function itemServicio(s) {
    const [texto, variante] = s.activo ? ESTADO[s.estado] : ['Pausado', 'warn'];
    const detalle = [
      destinoTexto(s),
      s.ultima_latencia_ms !== null ? `${s.ultima_latencia_ms} ms` : null,
      s.uptime_24h !== null ? `${porcentaje(s.uptime_24h)} en 24 h` : null,
      s.ssl_vence ? `certificado hasta ${Panel.fmtFecha(s.ssl_vence, true)}` : null,
    ].filter(Boolean).join(' · ');
    return el('button', {
      type: 'button', class: 'item',
      onclick: async () => { if (await ServiciosMonitor.editar(s, grupos())) cargar().catch(Panel.manejarError); },
    },
      el('div', { class: 'item-top' }, el('span', { class: 'item-titulo', text: s.nombre }), Panel.badge(texto, variante)),
      el('div', { class: 'item-sub', text: detalle }),
      s.estado === 'caido' && s.ultimo_detalle ? el('div', { class: 'item-sub negativo', text: s.ultimo_detalle }) : null,
      s.recientes.length ? el('div', { class: 'monitor-tira', 'aria-hidden': 'true' }, s.recientes.map((ok) => el('span', { class: ok ? 'ok' : 'fail' }))) : null,
      el('div', { class: 'item-sub', text: `Último chequeo: ${hace(s.hace_seg)}` }));
  }

  function pintarServicios() {
    if (!servicios.length) {
      Panel.llenar(contServicios, Panel.vacio('Agregá el primer servicio con “+ Servicio”: una web, un puerto o un certificado SSL.'));
      return;
    }
    const porGrupo = new Map();
    for (const s of servicios) {
      const clave = s.grupo || 'Sin grupo';
      if (!porGrupo.has(clave)) porGrupo.set(clave, []);
      porGrupo.get(clave).push(s);
    }
    Panel.llenar(contServicios, [...porGrupo].map(([grupo, lista]) =>
      el('div', { class: 'seccion' }, el('h2', { text: grupo }), el('div', { class: 'lista' }, lista.map(itemServicio)))));
  }

  function itemEvento(ev) {
    const cayo = ev.tipo === 'caida';
    const sub = [Panel.fmtFechaHora(ev.creado_en), ev.detalle, !cayo && ev.duracion_seg !== null ? `estuvo caído ${duracion(ev.duracion_seg)}` : null]
      .filter(Boolean).join(' · ');
    return el('div', { class: 'item' },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-titulo', text: ev.servicio_nombre }),
        Panel.badge(cayo ? 'Caída' : 'Recuperado', cayo ? 'danger' : 'ok')),
      el('div', { class: 'item-sub', text: sub }));
  }

  async function cargar() {
    const [lista, eventos] = await Promise.all([Panel.get('/api/monitor'), Panel.get('/api/monitor-eventos', { limite: 20 })]);
    servicios = lista;
    resumen();
    pintarServicios();
    Panel.llenar(contEventos, eventos.length ? el('div', { class: 'lista' }, eventos.map(itemEvento)) : Panel.vacio('Sin caídas registradas.'));
  }

  const botonChequear = Panel.boton('Chequear ahora', async () => {
    if (chequeando) return;
    chequeando = true;
    botonChequear.disabled = true;
    botonChequear.textContent = 'Chequeando…';
    try {
      const r = await Panel.post('/api/monitor/chequear');
      const partes = [`${r.chequeados} chequeados`, r.caidos ? `${r.caidos} caídos` : 'ninguno caído'];
      if (r.pendientes) partes.push(`${r.pendientes} quedaron para el próximo chequeo`);
      Panel.toast(partes.join(' · '));
      await cargar();
    } catch (err) {
      Panel.manejarError(err);
    } finally {
      chequeando = false;
      botonChequear.disabled = false;
      botonChequear.textContent = 'Chequear ahora';
    }
  });

  Panel.acciones(
    botonChequear,
    Panel.boton('+ Servicio', async () => { if (await ServiciosMonitor.nuevo(grupos())) cargar().catch(Panel.manejarError); }, 'primario'));

  cargar().catch(Panel.manejarError);
  // Se refresca solo mientras la pestaña está a la vista.
  setInterval(() => { if (!document.hidden && !chequeando) cargar().catch(() => {}); }, REFRESCO_MS);
})();
