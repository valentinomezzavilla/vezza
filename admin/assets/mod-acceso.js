'use strict';

const Acceso = (() => {
  const { el } = Panel;

  const ESTADOS = {
    sin_acceso: ['Sin acceso', ''],
    invitado: ['Invitación pendiente', 'warn'],
    activo: ['Activo', 'ok'],
    desactivado: ['Desactivado', 'danger'],
  };

  const url = (clienteId, accion) => `/api/acceso-cliente${Panel.qs({ cliente_id: clienteId, accion })}`;

  const dato = (label, valor) => el('div', {},
    el('div', { class: 'stat-label', text: label }),
    valor ? el('div', { text: valor }) : el('div', { class: 'item-sub', text: '—' }));

  function mostrarLink(r) {
    const ayuda = r.mail_enviado
      ? 'Le mandamos el mail con este link. Igual podés copiarlo.'
      : 'No pudimos mandar el mail. Copiá el link y mandáselo por WhatsApp o Instagram.';
    return Panel.modalForm({
      titulo: 'Invitación lista',
      campos: [{ name: 'link', label: 'Link de invitación (vale 72 horas)', soloLectura: true, ayuda }],
      valores: { link: r.link },
      textoBoton: 'Copiar link',
      enviar: async () => {
        await navigator.clipboard.writeText(r.link);
        Panel.toast('Link copiado');
        return { copiado: true };
      },
    });
  }

  async function invitar(clienteId, emailInicial) {
    const r = await Panel.modalForm({
      titulo: 'Invitar al portal',
      campos: [{
        name: 'email', label: 'Email del cliente', type: 'email', required: true,
        ayuda: 'Va a usar este email para ingresar. Si ya tenía una invitación, la anterior deja de funcionar.',
      }],
      valores: { email: emailInicial },
      textoBoton: 'Generar invitación',
      enviar: (d) => Panel.post(url(clienteId, 'invitar'), { email: d.email }),
    });
    if (!r) return false;
    await mostrarLink(r);
    return true;
  }

  async function cambiarActivo(clienteId, activo) {
    if (!activo && !(await Panel.confirmar('El cliente no va a poder ingresar al portal hasta que lo reactives.', 'Desactivar'))) return false;
    await Panel.post(url(clienteId, 'activo'), { activo });
    return true;
  }

  function vista(clienteId, cliente, acceso, recargar) {
    const [texto, variante] = ESTADOS[acceso.estado] ?? [acceso.estado, ''];
    const botones = [
      Panel.boton(acceso.estado === 'sin_acceso' ? 'Invitar al portal' : 'Enviar invitación nueva', async () => {
        if (await invitar(clienteId, acceso.email || cliente.email || '')) recargar();
      }, 'chico'),
    ];
    if (acceso.estado !== 'sin_acceso') {
      const activar = acceso.estado === 'desactivado';
      botones.push(Panel.boton(activar ? 'Reactivar acceso' : 'Desactivar acceso', async () => {
        try {
          if (await cambiarActivo(clienteId, activar)) recargar();
        } catch (err) {
          Panel.manejarError(err);
        }
      }, 'chico'));
    }
    return el('div', {},
      el('div', { class: 'item-top' },
        el('h2', { text: 'Acceso al portal' }),
        Panel.badge(texto, variante)),
      el('div', { class: 'datos' },
        dato('Email', acceso.email),
        dato('Último ingreso', acceso.ultimo_login ? Panel.fmtFechaHora(acceso.ultimo_login) : null),
        dato('La invitación vence', acceso.invitacion_vigente ? Panel.fmtFechaHora(acceso.invitacion_vence) : null)),
      el('div', { class: 'item-acciones seccion' }, botones));
  }

  return { vista };
})();
