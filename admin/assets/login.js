'use strict';

(() => {
  const boton = document.getElementById('ver-clave');
  const clave = document.getElementById('clave');
  if (!boton || !clave) return;
  boton.addEventListener('click', () => {
    const mostrar = clave.type === 'password';
    clave.type = mostrar ? 'text' : 'password';
    boton.textContent = mostrar ? 'Ocultar' : 'Mostrar';
    boton.setAttribute('aria-pressed', String(mostrar));
    clave.focus();
  });
})();
