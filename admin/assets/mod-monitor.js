'use strict';

const TIPOS_MONITOR = [['http', 'Web (HTTP / HTTPS)'], ['tcp', 'Puerto TCP'], ['ssl', 'Certificado SSL']];

const ServiciosMonitor = {
  campos(grupos) {
    return [
      { name: 'nombre', label: 'Nombre', required: true, placeholder: 'Sitio web, Base de datos, n8n…' },
      { name: 'grupo', label: 'Grupo', list: grupos, placeholder: 'VPS Hostinger', ayuda: 'Sirve para agrupar los servicios del mismo servidor.' },
      { name: 'tipo', label: 'Qué chequear', type: 'select', options: TIPOS_MONITOR, required: true },
      {
        name: 'destino', label: 'Destino', required: true, placeholder: 'https://vezzadev.com',
        ayuda: 'Web: la URL completa. Puerto TCP y SSL: solo el dominio o la IP (sin https://).',
      },
      { name: 'puerto', label: 'Puerto', type: 'number', min: 1, max: 65535, ayuda: 'Para Puerto TCP es obligatorio. SSL usa 443 si lo dejás vacío. En Web va dentro de la URL.' },
      { name: 'codigo_esperado', label: 'Código HTTP esperado', type: 'number', min: 100, max: 599, ayuda: 'Solo Web. Vacío = vale cualquier 2xx o 3xx.' },
      { name: 'intervalo_min', label: 'Chequear cada (minutos)', type: 'number', min: 1, max: 1440, required: true, default: 5 },
      { name: 'timeout_seg', label: 'Tiempo máximo de espera (segundos)', type: 'number', min: 1, max: 30, required: true, default: 10 },
      {
        name: 'fallos_para_caer', label: 'Fallos seguidos para dar la alerta', type: 'number', min: 1, max: 10, required: true, default: 2,
        ayuda: 'Con 2 evitás falsas alarmas por un corte de un solo chequeo.',
      },
      { name: 'ssl_dias_aviso', label: 'Avisar SSL con (días de anticipación)', type: 'number', min: 1, max: 90, required: true, default: 14, ayuda: 'Solo para el chequeo de certificado.' },
      { name: 'activo', label: 'Activo', type: 'checkbox', default: true },
    ];
  },
  nuevo(grupos) {
    return Panel.modalForm({ titulo: 'Nuevo servicio', campos: this.campos(grupos), enviar: (d) => Panel.post('/api/monitor', d) });
  },
  editar(s, grupos) {
    return Panel.modalForm({
      titulo: 'Editar servicio', campos: this.campos(grupos), valores: s,
      enviar: (d) => Panel.put(`/api/monitor/${s.id}`, d),
      eliminar: () => Panel.del(`/api/monitor/${s.id}`),
    });
  },
};
