'use strict';

const Panel = (() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

  class ApiError extends Error {
    constructor(mensaje, status, campos) {
      super(mensaje);
      this.status = status;
      this.campos = campos || {};
    }
  }

  function qs(params) {
    if (!params) return '';
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(params)) {
      if (v !== null && v !== undefined && v !== '') p.set(k, v);
    }
    const s = p.toString();
    return s ? `?${s}` : '';
  }

  async function api(metodo, url, cuerpo) {
    const opciones = { method: metodo, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf } };
    if (cuerpo instanceof FormData) {
      opciones.body = cuerpo;
    } else if (cuerpo !== undefined) {
      opciones.headers['Content-Type'] = 'application/json';
      opciones.body = JSON.stringify(cuerpo);
    }
    let res;
    try {
      res = await fetch(url, opciones);
    } catch {
      throw new ApiError('Sin conexión. Revisá tu internet y probá de nuevo.', 0);
    }
    if (res.status === 401) {
      location.href = `/admin-login?next=${encodeURIComponent(location.pathname + location.search)}`;
      throw new ApiError('Tu sesión expiró', 401);
    }
    if (res.status === 204) return null;
    let datos = null;
    try { datos = await res.json(); } catch { datos = null; }
    if (!res.ok) throw new ApiError(datos?.error || `Error ${res.status}`, res.status, datos?.campos);
    return datos;
  }

  const get = (url, params) => api('GET', url + qs(params)).then((r) => r.data);
  const post = (url, cuerpo) => api('POST', url, cuerpo ?? {}).then((r) => r?.data);
  const put = (url, cuerpo) => api('PUT', url, cuerpo).then((r) => r?.data);
  const del = (url) => api('DELETE', url).then(() => ({ eliminado: true }));

  function el(tag, attrs, ...hijos) {
    const nodo = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') nodo.className = v;
      else if (k === 'text') nodo.textContent = v;
      else if (k === 'dataset') Object.assign(nodo.dataset, v);
      else if (k.startsWith('on') && typeof v === 'function') nodo.addEventListener(k.slice(2).toLowerCase(), v);
      else if (v === true) nodo.setAttribute(k, '');
      else nodo.setAttribute(k, String(v));
    }
    for (const h of hijos.flat(Infinity)) {
      if (h === null || h === undefined || h === false) continue;
      nodo.append(h instanceof Node ? h : document.createTextNode(String(h)));
    }
    return nodo;
  }

  function llenar(nodo, ...hijos) {
    nodo.replaceChildren(...hijos.flat(Infinity).filter((h) => h !== null && h !== undefined && h !== false));
  }

  function toast(mensaje, tipo = 'ok') {
    const cont = document.getElementById('toasts');
    if (!cont) return;
    const t = el('div', { class: `toast${tipo === 'error' ? ' toast-error' : ''}`, role: tipo === 'error' ? 'alert' : 'status', text: mensaje });
    cont.append(t);
    setTimeout(() => t.remove(), tipo === 'error' ? 6000 : 3000);
  }

  function manejarError(err) {
    if (err?.status === 401) return;
    console.error(err);
    toast(err?.message || 'Algo salió mal', 'error');
  }

  function dialogo(titulo, contenido, pie) {
    const cerrar = el('button', { type: 'button', class: 'btn-cerrar', 'aria-label': 'Cerrar', text: '×' });
    const form = el('form', { novalidate: true },
      el('div', { class: 'modal-head' }, el('h2', { text: titulo }), cerrar),
      el('div', { class: 'modal-body' }, contenido),
      el('div', { class: 'modal-pie' }, pie));
    const d = el('dialog', { class: 'modal' }, form);
    cerrar.addEventListener('click', () => d.close());
    d.addEventListener('close', () => d.remove());
    document.body.append(d);
    d.showModal();
    return { d, form };
  }

  function confirmar(mensaje, textoBoton = 'Eliminar') {
    return new Promise((resolver) => {
      let ok = false;
      const cancelar = el('button', { type: 'button', class: 'btn', text: 'Cancelar' });
      const aceptar = el('button', { type: 'submit', class: 'btn btn-primario', text: textoBoton });
      const { d, form } = dialogo('Confirmar', el('p', { text: mensaje }), [cancelar, aceptar]);
      cancelar.addEventListener('click', () => d.close());
      form.addEventListener('submit', (e) => { e.preventDefault(); ok = true; d.close(); });
      d.addEventListener('close', () => resolver(ok));
      aceptar.focus();
    });
  }

  function crearCampo(def, valor) {
    const id = `f-${def.name}-${Math.random().toString(36).slice(2, 7)}`;
    const tipo = def.type || 'text';
    const inicial = valor === undefined ? (def.default ?? '') : (valor ?? '');
    let control;
    let datalist = null;
    if (tipo === 'textarea') {
      control = el('textarea', { id, name: def.name, rows: 4, placeholder: def.placeholder, disabled: def.soloLectura }, String(inicial));
    } else if (tipo === 'select') {
      control = el('select', { id, name: def.name, disabled: def.soloLectura });
      if (def.vacio !== undefined) control.append(el('option', { value: '', text: def.vacio }));
      for (const [value, label, grupo] of def.options || []) {
        control.append(el('option', { value, text: label, dataset: grupo !== undefined && grupo !== null ? { grupo: String(grupo) } : null }));
      }
      control.value = inicial === '' ? (control.options[0]?.value ?? '') : String(inicial);
    } else if (tipo === 'checkbox') {
      const marcado = valor === undefined || valor === null ? Boolean(def.default) : Boolean(Number(valor));
      control = el('input', { id, name: def.name, type: 'checkbox', checked: marcado, disabled: def.soloLectura });
    } else {
      let v = String(inicial);
      if (tipo === 'datetime-local' && v) v = v.replace(' ', 'T').slice(0, 16);
      const attrs = {
        id, name: def.name, type: tipo === 'money' ? 'text' : tipo, value: v, placeholder: def.placeholder,
        autocomplete: 'off', maxlength: def.maxlength, min: def.min, max: def.max, disabled: def.soloLectura,
      };
      if (tipo === 'money') attrs.inputmode = 'decimal';
      if (tipo === 'number') attrs.inputmode = 'numeric';
      if (def.list) {
        attrs.list = `${id}-list`;
        datalist = el('datalist', { id: `${id}-list` }, def.list.map((x) => el('option', { value: x })));
      }
      control = el('input', attrs);
    }
    const error = el('div', { class: 'campo-error' });
    const label = el('label', { for: id, text: def.label + (def.required ? ' *' : '') });
    const ayuda = def.ayuda ? el('small', { class: 'item-sub', text: def.ayuda }) : null;
    const fila = tipo === 'checkbox'
      ? el('div', { class: 'campo' }, el('div', { class: 'campo-check' }, control, label), ayuda, error)
      : el('div', { class: 'campo' }, label, control, datalist, ayuda, error);
    return { fila, control };
  }

  function filtrarOpciones(select, valor) {
    for (const op of select.options) {
      if (op.dataset.grupo === undefined) continue;
      const visible = valor !== '' && op.dataset.grupo === String(valor);
      op.hidden = !visible;
      op.disabled = !visible;
    }
  }

  function leerValores(controles) {
    const datos = {};
    for (const [nombre, { def, control }] of Object.entries(controles)) {
      if (def.soloLectura) continue;
      if (def.type === 'checkbox') {
        datos[nombre] = control.checked;
      } else {
        const v = control.value.trim();
        datos[nombre] = v === '' ? null : v;
      }
    }
    return datos;
  }

  function modalForm({ titulo, campos, valores = {}, enviar, eliminar = null, textoBoton = 'Guardar' }) {
    return new Promise((resolver) => {
      let resultado = null;
      const errorGeneral = el('div', { class: 'form-error', role: 'alert', hidden: true });
      const controles = {};
      const filas = campos.map((def) => {
        const { fila, control } = crearCampo(def, valores[def.name]);
        controles[def.name] = { def, control, fila };
        return fila;
      });
      for (const { def, control } of Object.values(controles)) {
        if (!def.filtro || !controles[def.filtro]) continue;
        const origen = controles[def.filtro].control;
        filtrarOpciones(control, origen.value);
        origen.addEventListener('change', () => {
          filtrarOpciones(control, origen.value);
          if (control.selectedOptions[0]?.hidden) control.value = '';
        });
      }

      const cancelar = el('button', { type: 'button', class: 'btn', text: 'Cancelar' });
      const guardar = el('button', { type: 'submit', class: 'btn btn-primario', text: textoBoton });
      const pie = [];
      if (eliminar) {
        pie.push(el('button', {
          type: 'button', class: 'btn btn-peligro', text: 'Eliminar',
          onclick: async () => {
            if (!(await confirmar('¿Seguro que querés eliminarlo? No se puede deshacer.'))) return;
            try { resultado = (await eliminar()) || { eliminado: true }; d.close(); } catch (err) { mostrarError(err); }
          },
        }), el('span', { class: 'separador' }));
      }
      pie.push(cancelar, guardar);
      const { d, form } = dialogo(titulo, [errorGeneral, ...filas], pie);
      cancelar.addEventListener('click', () => d.close());
      d.addEventListener('close', () => resolver(resultado));

      function mostrarError(err) {
        for (const { fila } of Object.values(controles)) {
          fila.classList.remove('invalido');
          fila.querySelector('.campo-error').textContent = '';
        }
        if (err?.status === 401) return;
        errorGeneral.textContent = err?.message || 'Algo salió mal';
        errorGeneral.hidden = false;
        let primero = null;
        for (const [nombre, msg] of Object.entries(err?.campos || {})) {
          const c = controles[nombre];
          if (!c) continue;
          c.fila.classList.add('invalido');
          c.fila.querySelector('.campo-error').textContent = msg;
          primero ??= c.control;
        }
        primero?.focus();
      }

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        guardar.disabled = true;
        errorGeneral.hidden = true;
        try {
          resultado = await enviar(leerValores(controles));
          d.close();
        } catch (err) {
          mostrarError(err);
        } finally {
          guardar.disabled = false;
        }
      });
      form.querySelector('input:not([disabled]), select:not([disabled]), textarea:not([disabled])')?.focus();
    });
  }

  function aFecha(isoTexto) {
    const [y, m, d] = String(isoTexto).slice(0, 10).split('-').map(Number);
    return new Date(y, m - 1, d);
  }
  const iso = (f) => `${f.getFullYear()}-${String(f.getMonth() + 1).padStart(2, '0')}-${String(f.getDate()).padStart(2, '0')}`;
  const hoy = () => iso(new Date());
  const diasHasta = (isoTexto) => Math.round((aFecha(isoTexto) - aFecha(hoy())) / 86400000);

  function fmtMonto(monto, moneda) {
    const n = Number(monto);
    try {
      return new Intl.NumberFormat('es-AR', { style: 'currency', currency: moneda || 'ARS' }).format(n);
    } catch {
      return `${moneda} ${n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
  }
  function fmtFecha(isoTexto, conAnio = false) {
    if (!isoTexto) return '';
    const f = aFecha(isoTexto);
    const opciones = { day: 'numeric', month: 'short' };
    if (conAnio || f.getFullYear() !== new Date().getFullYear()) opciones.year = 'numeric';
    return f.toLocaleDateString('es-AR', opciones);
  }
  const fmtHora = (dt) => String(dt).slice(11, 16);
  const fmtFechaHora = (dt) => `${fmtFecha(dt)} ${fmtHora(dt)}`;

  const ETQ = {
    estadoCliente: { activo: 'Activo', pausado: 'Pausado', finalizado: 'Finalizado' },
    estadoProceso: { por_hacer: 'Por hacer', en_curso: 'En curso', en_revision: 'En revisión', entregado: 'Entregado' },
    prioridad: { alta: 'Alta', media: 'Media', baja: 'Baja' },
    estadoCobro: { pendiente: 'Pendiente', vencido: 'Vencido', pagado: 'Pagado' },
    estadoTarea: { pendiente: 'Pendiente', en_curso: 'En curso', hecha: 'Hecha' },
    estadoFix: { reportado: 'Reportado', en_progreso: 'En progreso', resuelto: 'Resuelto' },
    tipoEvento: { reunion: 'Reunión', llamada: 'Llamada', recordatorio: 'Recordatorio', otro: 'Otro' },
    frecuencia: { mensual: 'Mensual', anual: 'Anual' },
  };
  const VARIANTE = {
    activo: 'ok', pagado: 'ok', entregado: 'ok', hecha: 'ok', resuelto: 'ok',
    vencido: 'danger', alta: 'danger',
    pausado: 'warn', pendiente: 'warn', reportado: 'warn',
    en_curso: 'accent', en_revision: 'accent', en_progreso: 'accent',
  };
  const opciones = (mapa) => Object.entries(mapa);
  const badge = (texto, variante) => el('span', { class: `badge${variante ? ` badge-${variante}` : ''}`, text: texto });
  const badgeEstado = (mapa, valor) => badge(ETQ[mapa]?.[valor] ?? valor, VARIANTE[valor]);
  const vacio = (texto) => el('div', { class: 'vacio', text: texto });

  let cacheClientes = null;
  function clientes(refrescar = false) {
    if (refrescar || !cacheClientes) {
      cacheClientes = get('/api/clientes').catch((err) => { cacheClientes = null; throw err; });
    }
    return cacheClientes;
  }
  const opcionesClientes = (lista) => lista.map((c) => [String(c.id), c.nombre]);

  const boton = (texto, onclick, variante = '') =>
    el('button', { type: 'button', class: `btn${variante ? ` btn-${variante}` : ''}`, text: texto, onclick });
  const acciones = (...botones) => llenar(document.getElementById('page-actions'), botones);

  function tabs(contenedor, lista) {
    const panel = el('div', { role: 'tabpanel' });
    const botones = lista.map((t) => el('button', { type: 'button', role: 'tab', 'aria-selected': 'false', text: t.label, onclick: () => activar(t.id) }));
    llenar(contenedor, el('div', { class: 'tabs', role: 'tablist' }, botones), panel);
    function activar(id) {
      const t = lista.find((x) => x.id === id) || lista[0];
      lista.forEach((x, i) => botones[i].setAttribute('aria-selected', String(x.id === t.id)));
      history.replaceState(null, '', `${location.pathname}${location.search}#${t.id}`);
      llenar(panel, el('p', { class: 'item-sub', text: 'Cargando…' }));
      Promise.resolve().then(() => t.render(panel)).catch(manejarError);
    }
    activar(location.hash.slice(1));
    return { activar, actual: () => location.hash.slice(1) };
  }

  document.addEventListener('click', (e) => {
    const abierto = document.querySelector('.nav-mas[open]');
    if (abierto && !abierto.contains(e.target)) abierto.removeAttribute('open');
  });

  return {
    ApiError, api, get, post, put, del, qs, el, llenar, toast, manejarError, confirmar, modalForm,
    aFecha, iso, hoy, diasHasta, fmtMonto, fmtFecha, fmtHora, fmtFechaHora,
    ETQ, opciones, badge, badgeEstado, vacio, clientes, opcionesClientes, boton, acciones, tabs,
  };
})();
