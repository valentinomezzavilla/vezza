'use strict';

// Sistemas del cliente conectados al admin: los que le hicimos (repo y URL), cada uno con su token. Con ese token el sistema
// muestra a su usuario el estado de su cuenta en el módulo "Acceder a VEZZA". El token se ve una sola vez, al generarlo.
const Sistemas = (() => {
  const { el } = Panel;

  const CAMPOS = [
    { name: 'nombre', label: 'Nombre', required: true, placeholder: 'Sistema de gestión' },
    { name: 'repo', label: 'Repo de GitHub', placeholder: 'valentinomezzavilla/pesca_valle_viejo', ayuda: 'dueño/nombre, o el link del repo.' },
    { name: 'url', label: 'URL del sistema', type: 'url', placeholder: 'https://…' },
  ];

  /** Las dos líneas que van en el .env del sistema. */
  const lineasEnv = (token) => `VEZZA_URL=${location.origin}\nVEZZA_TOKEN=${token}`;

  function mostrarToken(r) {
    const texto = lineasEnv(r.token);
    return Panel.modalForm({
      titulo: `Token de ${r.nombre}`,
      campos: [{
        name: 'env', label: 'Variables para el .env del sistema', type: 'textarea', soloLectura: true,
        ayuda: 'Copialas ahora: el token no se vuelve a mostrar. Si lo perdés, generá uno nuevo.',
      }],
      valores: { env: texto },
      textoBoton: 'Copiar',
      enviar: async () => {
        await navigator.clipboard.writeText(texto);
        Panel.toast('Copiado');
        return { copiado: true };
      },
    });
  }

  async function nuevo(clienteId) {
    const r = await Panel.modalForm({
      titulo: 'Conectar un sistema',
      campos: CAMPOS,
      textoBoton: 'Conectar',
      enviar: (d) => Panel.post('/api/sistemas', { ...d, cliente_id: clienteId }),
    });
    if (!r) return false;
    await mostrarToken(r);
    return true;
  }

  async function editar(s) {
    return Panel.modalForm({
      titulo: 'Editar sistema',
      campos: CAMPOS,
      valores: s,
      enviar: (d) => Panel.put(`/api/sistemas/${s.id}`, d),
      eliminar: () => Panel.del(`/api/sistemas/${s.id}`),
    });
  }

  async function regenerar(s) {
    const aviso = s.conectado ? 'El token actual deja de funcionar: vas a tener que cambiarlo en el .env del sistema.' : 'Se genera el token del sistema.';
    if (!(await Panel.confirmar(aviso, 'Generar token'))) return false;
    await mostrarToken(await Panel.post(`/api/sistemas/${s.id}/token`));
    return true;
  }

  function contacto(s) {
    if (!s.ultimo_contacto) return ['Nunca se conectó', 'warn'];
    const dias = -Panel.diasHasta(s.ultimo_contacto.slice(0, 10));
    return [`Último contacto: ${Panel.fmtFechaHora(s.ultimo_contacto)}`, dias > 2 ? 'warn' : 'ok'];
  }

  function item(s, recargar) {
    const [texto, variante] = contacto(s);
    const conError = (fn) => async () => {
      try {
        if (await fn()) recargar();
      } catch (err) {
        Panel.manejarError(err);
      }
    };
    return el('article', { class: 'item' },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-titulo', text: s.nombre }),
        Panel.badge(texto, variante)),
      el('div', { class: 'item-acciones' },
        s.comprobantes_sin_copiar
          ? Panel.badge(`Le ${s.comprobantes_sin_copiar === 1 ? 'falta copiar 1 comprobante' : `faltan copiar ${s.comprobantes_sin_copiar} comprobantes`}`, 'warn')
          : Panel.badge('Comprobantes al día', 'ok')),
      el('div', { class: 'item-sub' },
        s.repo ? el('a', { href: `https://github.com/${s.repo}`, target: '_blank', rel: 'noopener', text: s.repo }) : 'Sin repo',
        s.url ? ' · ' : '',
        s.url ? el('a', { href: s.url, target: '_blank', rel: 'noopener', text: s.url.replace(/^https?:\/\//, '') }) : ''),
      el('div', { class: 'item-acciones' },
        Panel.boton('Editar', conError(() => editar(s)), 'chico'),
        Panel.boton(s.conectado ? 'Generar token nuevo' : 'Generar token', conError(() => regenerar(s)), 'chico')));
  }

  function vista(clienteId, sistemas, recargar) {
    return el('div', {},
      el('div', { class: 'item-top' },
        el('h2', { text: 'Sistemas' }),
        Panel.boton('+ Conectar sistema', async () => {
          try {
            if (await nuevo(clienteId)) recargar();
          } catch (err) {
            Panel.manejarError(err);
          }
        }, 'chico')),
      el('p', { class: 'item-sub', text: 'Cada sistema muestra el estado de esta cuenta en su módulo "Acceder a VEZZA".' }),
      sistemas.length
        ? el('div', { class: 'lista seccion' }, sistemas.map((s) => item(s, recargar)))
        : Panel.vacio('Este cliente todavía no tiene sistemas conectados.'));
  }

  return { vista };
})();
