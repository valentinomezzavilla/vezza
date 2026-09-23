'use strict';

function kanbanBoard(contenedor, { columnas, items, tarjeta, alMover }) {
  const { el } = Panel;
  const tablero = el('div', { class: 'kanban' });
  for (const [estado, titulo] of columnas) {
    const suyos = items.filter((i) => i.estado === estado);
    const lista = el('ul', { class: 'kanban-lista', dataset: { estado } },
      suyos.map((i) => el('li', { class: 'kanban-card', dataset: { id: String(i.id) } }, tarjeta(i))));
    tablero.append(el('section', { class: 'kanban-col', 'aria-label': titulo },
      el('h3', {}, el('span', { text: titulo }), Panel.badge(String(suyos.length))),
      lista));
    Sortable.create(lista, {
      group: 'kanban',
      animation: 150,
      delay: 180,
      delayOnTouchOnly: true,
      filter: 'select, a, button, input',
      preventOnFilter: false,
      onEnd: (evt) => {
        if (evt.from === evt.to && evt.oldIndex === evt.newIndex) return;
        const siguiente = evt.item.nextElementSibling;
        alMover(Number(evt.item.dataset.id), evt.to.dataset.estado, siguiente ? Number(siguiente.dataset.id) : null);
      },
    });
  }
  Panel.llenar(contenedor, tablero);
}
