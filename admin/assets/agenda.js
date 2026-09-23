'use strict';

(() => {
  const { el } = Panel;
  const grid = document.getElementById('cal-grid');
  const lista = document.getElementById('cal-lista');
  const titulo = document.getElementById('mes-titulo');
  const hoyIso = Panel.hoy();
  const base = Panel.aFecha(hoyIso);
  let anio = base.getFullYear();
  let mes = base.getMonth();
  const PREFIJO = { evento: '', cobro: 'Cobro: ', suscripcion: 'Pago: ', entrega: 'Entrega: ', tarea: 'Tarea: ' };

  Panel.acciones(Panel.boton('+ Evento', () => nuevoEvento(hoyIso), 'primario'));

  async function nuevoEvento(fecha) {
    if (await Eventos.nuevo({ fecha_hora: `${fecha} 10:00` })) cargar();
  }

  function rango() {
    const primero = new Date(anio, mes, 1);
    const inicio = new Date(anio, mes, 1 - ((primero.getDay() + 6) % 7));
    const ultimo = new Date(anio, mes + 1, 0);
    const fin = new Date(anio, mes, ultimo.getDate() + (6 - ((ultimo.getDay() + 6) % 7)));
    return { inicio, fin };
  }

  function textoItem(i) {
    let t = PREFIJO[i.tipo] + i.titulo;
    if (i.monto) t += ` · ${Panel.fmtMonto(i.monto, i.moneda)}`;
    if (i.tipo === 'entrega' && i.cliente_nombre) t += ` (${i.cliente_nombre})`;
    return t;
  }

  async function abrir(i) {
    if (i.tipo === 'evento') {
      const ev = await Panel.get(`/api/eventos/${i.ref}`);
      if (await Eventos.editar(ev)) cargar();
    } else if (i.tipo === 'cobro') {
      location.href = `/admin/cobros?cliente_id=${i.cliente_id}#${i.vencido ? 'vencidos' : 'pendientes'}`;
    } else if (i.tipo === 'suscripcion') {
      location.href = '/admin/gastos';
    } else if (i.tipo === 'entrega') {
      location.href = `/admin/procesos/${i.ref}`;
    } else if (i.tipo === 'tarea') {
      location.href = '/admin/tareas';
    }
  }

  function botonItem(i) {
    const texto = textoItem(i);
    return el('button', {
      type: 'button', class: `cal-item t-${i.tipo}${i.vencido ? ' vencido' : ''}`, title: texto,
      onclick: () => abrir(i).catch(Panel.manejarError),
    },
      i.hora ? el('span', { class: 'hora', text: i.hora }) : null,
      el('span', { class: 'titulo', text: texto }));
  }

  function renderGrid(inicio, fin, porDia) {
    const celdas = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].map((d) => el('div', { class: 'cab', text: d }));
    for (let d = new Date(inicio); d <= fin; d.setDate(d.getDate() + 1)) {
      const f = Panel.iso(d);
      const clases = ['cal-celda'];
      if (d.getMonth() !== mes) clases.push('fuera');
      if (f === hoyIso) clases.push('hoy');
      celdas.push(el('div', { class: clases.join(' ') },
        el('button', { type: 'button', class: 'num-dia', text: String(d.getDate()), 'aria-label': `Nuevo evento el ${Panel.fmtFecha(f, true)}`, onclick: () => nuevoEvento(f) }),
        (porDia[f] || []).map(botonItem)));
    }
    Panel.llenar(grid, celdas);
  }

  function renderLista(porDia) {
    const prefijoMes = `${anio}-${String(mes + 1).padStart(2, '0')}`;
    const dias = Object.keys(porDia).filter((f) => f.startsWith(prefijoMes)).sort();
    if (!dias.length) {
      Panel.llenar(lista, Panel.vacio('No hay nada agendado este mes.'));
      return;
    }
    Panel.llenar(lista, dias.map((f) => el('section', { class: `cal-dia${f === hoyIso ? ' hoy' : ''}` },
      el('h3', { text: Panel.aFecha(f).toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' }) }),
      porDia[f].map(botonItem))));
  }

  async function cargar() {
    const { inicio, fin } = rango();
    titulo.textContent = new Date(anio, mes, 1).toLocaleDateString('es-AR', { month: 'long', year: 'numeric' });
    try {
      const items = await Panel.get('/api/agenda', { desde: Panel.iso(inicio), hasta: Panel.iso(fin) });
      const porDia = {};
      for (const i of items) (porDia[i.fecha] ||= []).push(i);
      renderGrid(inicio, fin, porDia);
      renderLista(porDia);
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  document.getElementById('mes-anterior').addEventListener('click', () => {
    mes -= 1;
    if (mes < 0) { mes = 11; anio -= 1; }
    cargar();
  });
  document.getElementById('mes-siguiente').addEventListener('click', () => {
    mes += 1;
    if (mes > 11) { mes = 0; anio += 1; }
    cargar();
  });
  document.getElementById('mes-hoy').addEventListener('click', () => {
    anio = base.getFullYear();
    mes = base.getMonth();
    cargar();
  });
  cargar();
})();
