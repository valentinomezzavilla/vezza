'use strict';

// Botón "Mostrar / Ocultar" de los campos de contraseña.
document.querySelectorAll('[data-ver-clave]').forEach((boton) => {
  const campo = document.getElementById(boton.dataset.verClave);
  if (!campo) return;
  boton.addEventListener('click', () => {
    const mostrar = campo.type === 'password';
    campo.type = mostrar ? 'text' : 'password';
    boton.textContent = mostrar ? 'Ocultar' : 'Mostrar';
    boton.setAttribute('aria-pressed', String(mostrar));
    campo.focus();
  });
});
