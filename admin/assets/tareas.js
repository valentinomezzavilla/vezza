'use strict';

(() => {
  const { el } = Panel;
  let tabs;
  const recargar = () => tabs.activar(tabs.actual());

  const campos = () => [
    { name: 'titulo', label: 'Título', required: true },
    { name: 'descripcion', label: 'Descripción', type: 'textarea' },
    { name: 'estado', label: 'Estado', type: 'select', options: Panel.opciones(Panel.ETQ.estadoTarea) },
    { name: 'prioridad', label: 'Prioridad', type: 'select', options: Panel.opciones(Panel.ETQ.prioridad), default: 'media' },
    { name: 'fecha_vencimiento', label: 'Vence', type: 'date' },
  ];

  async function editar(t) {
    const r = await Panel.modalForm({
      titulo: 'Editar tarea', campos: campos(), valores: t,
      enviar: (d) => Panel.put(`/api/tareas/${t.id}`, d),
      eliminar: () => Panel.del(`/api/tareas/${t.id}`),
    });
    if (r) recargar();
  }

  function item(t) {
    const hecha = t.estado === 'hecha';
    const check = el('input', { type: 'checkbox', checked: hecha, 'aria-label': `Marcar "${t.titulo}" como hecha` });
    check.addEventListener('change', async () => {
      try {
        await Panel.put(`/api/tareas/${t.id}`, { estado: check.checked ? 'hecha' : 'pendiente' });
        recargar();
      } catch (err) {
        check.checked = !check.checked;
        Panel.manejarError(err);
      }
    });
    const dias = t.fecha_vencimiento ? Panel.diasHasta(t.fecha_vencimiento) : null;
    let variante = '';
    if (!hecha && dias !== null && dias < 0) variante = 'danger';
    else if (!hecha && dias !== null && dias <= 2) variante = 'warn';
    return el('div', { class: `check-item${hecha ? ' hecha' : ''}` },
      check,
      el('button', { type: 'button', class: 'texto boton-texto', onclick: () => editar(t) },
        el('span', { class: 'item-titulo', text: t.titulo }),
        el('span', { class: 'meta' },
          t.estado === 'en_curso' ? Panel.badgeEstado('estadoTarea', 'en_curso') : null,
          t.prioridad !== 'media' ? Panel.badgeEstado('prioridad', t.prioridad) : null,
          t.fecha_vencimiento ? Panel.badge(`Vence ${Panel.fmtFecha(t.fecha_vencimiento)}`, variante) : null)));
  }

  const render = (filtro, textoVacio) => async (panel) => {
    const tareas = await Panel.get('/api/tareas', filtro);
    Panel.llenar(panel, tareas.length ? el('div', { class: 'lista' }, tareas.map(item)) : Panel.vacio(textoVacio));
  };

  Panel.acciones(Panel.boton('+ Nueva tarea', async () => {
    const r = await Panel.modalForm({ titulo: 'Nueva tarea', campos: campos(), enviar: (d) => Panel.post('/api/tareas', d) });
    if (r) recargar();
  }, 'primario'));

  tabs = Panel.tabs(document.getElementById('tareas'), [
    { id: 'abiertas', label: 'Abiertas', render: render({ abiertas: 1 }, 'No tenés tareas abiertas.') },
    { id: 'hechas', label: 'Hechas', render: render({ estado: 'hecha' }, 'Todavía no completaste tareas.') },
    { id: 'todas', label: 'Todas', render: render({}, 'Todavía no cargaste tareas.') },
  ]);
})();
