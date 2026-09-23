'use strict';

(() => {
  const { el } = Panel;
  const id = Number(document.body.dataset.procesoId);
  const ficha = document.getElementById('ficha');
  const checklist = document.getElementById('checklist');

  const dato = (label, valor) => el('div', {}, el('div', { class: 'stat-label', text: label }), el('div', { text: valor || '—' }));
  const recargarFicha = () => cargarFicha().catch(Panel.manejarError);
  const recargarSubtareas = () => cargarSubtareas().catch(Panel.manejarError);

  async function cargarFicha() {
    const p = await Panel.get(`/api/procesos/${id}`);
    document.getElementById('titulo').textContent = p.titulo;
    document.title = `${p.titulo} · VEZZA Admin`;
    Panel.llenar(ficha,
      el('div', { class: 'item-top' },
        el('a', { href: `/admin/clientes/${p.cliente_id}`, text: p.cliente_nombre }),
        Panel.boton('Editar', async () => {
          const r = await Procesos.editar(p);
          if (r?.eliminado) location.href = '/admin/procesos';
          else if (r) recargarFicha();
        }, 'chico')),
      p.descripcion ? el('p', { class: 'nota-texto', text: p.descripcion }) : null,
      Procesos.resumen(p),
      el('div', { class: 'item-acciones' }, Procesos.selectorEstado(p, recargarFicha)),
      el('div', { class: 'datos' },
        dato('Inicio', p.fecha_inicio && Panel.fmtFecha(p.fecha_inicio, true)),
        dato('Entrega estimada', p.fecha_entrega_estimada && Panel.fmtFecha(p.fecha_entrega_estimada, true))));
  }

  async function mover(s, antesDe) {
    try {
      await Panel.put(`/api/subtareas/${s.id}`, { antes_de: antesDe });
      await cargarSubtareas();
      // El foco vuelve a la subtarea movida para poder seguir moviéndola con el teclado.
      checklist.querySelector(`[data-id="${s.id}"] .texto`)?.focus();
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  function itemSubtarea(s, i, todas) {
    const check = el('input', { type: 'checkbox', checked: Boolean(s.completada), 'aria-label': `Completar "${s.titulo}"` });
    check.addEventListener('change', async () => {
      try {
        await Panel.put(`/api/subtareas/${s.id}`, { completada: check.checked });
        recargarSubtareas();
        recargarFicha();
      } catch (err) {
        check.checked = !check.checked;
        Panel.manejarError(err);
      }
    });
    // Alternativa al arrastre (WCAG 2.2): subir va antes de la anterior; bajar, antes de la que sigue a la siguiente.
    const subir = el('button', {
      type: 'button', class: 'btn btn-chico btn-icono', 'aria-label': `Subir "${s.titulo}"`, disabled: i === 0,
      onclick: () => mover(s, todas[i - 1].id),
    }, Panel.icono('subir'));
    const bajar = el('button', {
      type: 'button', class: 'btn btn-chico btn-icono', 'aria-label': `Bajar "${s.titulo}"`, disabled: i === todas.length - 1,
      onclick: () => mover(s, todas[i + 2] ? todas[i + 2].id : null),
    }, Panel.icono('bajar'));
    return el('li', { class: `check-item${s.completada ? ' hecha' : ''}`, dataset: { id: String(s.id) } },
      el('span', { class: 'asa' }, Panel.icono('arrastrar')),
      check,
      el('button', { type: 'button', class: 'texto boton-texto item-titulo', text: s.titulo, 'aria-label': `Editar "${s.titulo}"`, onclick: () => editarSubtarea(s) }),
      el('span', { class: 'mover' }, subir, bajar));
  }

  function formNueva() {
    const input = el('input', { class: 'filtro', placeholder: 'Nueva subtarea', 'aria-label': 'Nueva subtarea', maxlength: 255 });
    const form = el('form', { class: 'toolbar' }, input, el('button', { type: 'submit', class: 'btn btn-primario', text: 'Agregar' }));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const titulo = input.value.trim();
      if (!titulo) return;
      try {
        await Panel.post('/api/subtareas', { proceso_id: id, titulo });
        await cargarSubtareas();
        checklist.querySelector('input.filtro')?.focus();
        recargarFicha();
      } catch (err) {
        Panel.manejarError(err);
      }
    });
    return form;
  }

  async function editarSubtarea(s) {
    const r = await Panel.modalForm({
      titulo: 'Editar subtarea',
      campos: [{ name: 'titulo', label: 'Título', required: true }],
      valores: s,
      enviar: (d) => Panel.put(`/api/subtareas/${s.id}`, d),
      eliminar: () => Panel.del(`/api/subtareas/${s.id}`),
    });
    if (r) { recargarSubtareas(); recargarFicha(); }
  }

  async function cargarSubtareas() {
    const subtareas = await Panel.get('/api/subtareas', { proceso_id: id });
    const hechas = subtareas.filter((s) => s.completada).length;
    const barra = el('span');
    barra.style.width = subtareas.length ? `${Math.round((hechas / subtareas.length) * 100)}%` : '0%';
    const ul = el('ul', { class: 'checklist' }, subtareas.map(itemSubtarea));
    Panel.llenar(checklist,
      formNueva(),
      el('div', { class: 'item-sub', text: `${hechas} de ${subtareas.length} completas` }),
      el('div', { class: 'progreso' }, barra),
      subtareas.length ? ul : Panel.vacio('Sumá la primera subtarea para armar el checklist.'));
    Sortable.create(ul, {
      handle: '.asa',
      animation: Panel.animacion(),
      onEnd: async (evt) => {
        if (evt.oldIndex === evt.newIndex) return;
        const siguiente = evt.item.nextElementSibling;
        try {
          await Panel.put(`/api/subtareas/${evt.item.dataset.id}`, { antes_de: siguiente ? Number(siguiente.dataset.id) : null });
        } catch (err) {
          Panel.manejarError(err);
        }
        recargarSubtareas();
      },
    });
  }

  Promise.all([cargarFicha(), cargarSubtareas()]).catch(Panel.manejarError);
})();
