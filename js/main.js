/* ==========================================================================
   VEZZA · interacciones
   Vanilla JS, sin dependencias. Todo responde a acciones de la persona;
   las únicas cosas que se mueven solas son el hero (en CSS) y el video de
   VEZZA Shop, que se puede pausar y no arranca con "reducir movimiento".
   ========================================================================== */
(function () {
  'use strict';

  var header = document.querySelector('[data-header]');

  /* ---------- 1. Header: línea inferior al scrollear ---------- */
  function initHeaderState() {
    if (!header) return;
    var update = function () {
      header.classList.toggle('is-scrolled', window.scrollY > 8);
    };
    update();
    window.addEventListener('scroll', update, { passive: true });
  }

  /* ---------- 2. Menú mobile ---------- */
  function initMenu() {
    var toggle = document.querySelector('[data-menu-toggle]');
    var nav = document.querySelector('[data-nav]');
    if (!header || !toggle || !nav) return;

    var desktop = window.matchMedia('(min-width: 64em)');

    function setOpen(open, returnFocus) {
      header.classList.toggle('is-open', open);
      document.documentElement.classList.toggle('menu-open', open); // sin scroll de fondo
      toggle.setAttribute('aria-expanded', String(open));
      toggle.querySelector('.menu-toggle__label').textContent = open ? 'Cerrar' : 'Menú';
      if (!open && returnFocus) toggle.focus();
    }

    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') !== 'true';
      setOpen(open);
      if (open) {
        var first = nav.querySelector('a');
        if (first) first.focus();
      }
    });

    // Tocar un link cierra el panel
    nav.addEventListener('click', function (e) {
      if (e.target.closest('a') && !desktop.matches) setOpen(false);
    });

    // Esc cierra y devuelve el foco al botón
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && header.classList.contains('is-open')) setOpen(false, true);
    });

    // Si el foco sale del header (Tab), se cierra
    header.addEventListener('focusout', function (e) {
      if (header.classList.contains('is-open') && !header.contains(e.relatedTarget)) setOpen(false);
    });

    // Click afuera
    document.addEventListener('click', function (e) {
      if (header.classList.contains('is-open') && !header.contains(e.target)) setOpen(false);
    });

    // Al pasar a escritorio, reseteo el estado
    desktop.addEventListener('change', function () { setOpen(false); });
  }

  /* ---------- 3. Link activo según la sección visible ---------- */
  function initActiveLink() {
    if (!('IntersectionObserver' in window)) return;
    var links = document.querySelectorAll('.site-nav__link');
    var map = {};
    links.forEach(function (link) { map[link.getAttribute('href').slice(1)] = link; });

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var link = map[entry.target.id];
        if (!link || !entry.isIntersecting) return;
        links.forEach(function (l) {
          l.classList.remove('is-active');
          l.removeAttribute('aria-current');
        });
        link.classList.add('is-active');
        link.setAttribute('aria-current', 'true');
      });
    }, { rootMargin: '-45% 0px -50% 0px' });

    document.querySelectorAll('main section[id]').forEach(function (s) { observer.observe(s); });
  }

  /* ---------- 4. Servicios: desplegar la descripción ----------
     Se abre al pasar el mouse, al enfocar con teclado o al tocar.
     Estado de cada fila = override (click/tap) ?? (hover || foco).
     La fila con data-sticky (E-commerce, con el video de VEZZA Shop) arranca
     abierta y solo se abre o cierra con su propio clic: con un panel tan alto,
     abrirla al pasar el mouse o cerrarla cuando se abre otra fila movería toda
     la lista, y al tabular hacia el botón de pausa del video se cerraría sola. */
  function initServices() {
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-service]'));
    if (!rows.length) return;

    var state = new Map();
    var HOVER_DELAY = 60; // evita aperturas al cruzar la lista rápido

    function sync(row) {
      var s = state.get(row);
      var open = s.override !== null ? s.override : (s.hover || s.focus);
      row.classList.toggle('is-open', open);
      s.button.setAttribute('aria-expanded', String(open));
    }

    rows.forEach(function (row) {
      var button = row.querySelector('.service__toggle');
      var sticky = row.hasAttribute('data-sticky');
      var s = { button: button, hover: false, focus: false, override: sticky ? true : null, timer: 0 };
      state.set(row, s);

      row.addEventListener('pointerenter', function (e) {
        if (sticky || e.pointerType !== 'mouse') return;
        clearTimeout(s.timer);
        s.timer = setTimeout(function () { s.hover = true; sync(row); }, HOVER_DELAY);
      });

      row.addEventListener('pointerleave', function (e) {
        if (sticky || e.pointerType !== 'mouse') return;
        clearTimeout(s.timer);
        s.hover = false;
        if (!s.focus) s.override = null;
        sync(row);
      });

      // Solo foco de teclado (en touch, el tap también enfoca y lo maneja el click)
      button.addEventListener('focus', function () {
        if (sticky || !button.matches(':focus-visible')) return;
        s.focus = true;
        sync(row);
      });

      button.addEventListener('blur', function () {
        if (sticky) return;
        s.focus = false;
        if (!s.hover) s.override = null;
        sync(row);
      });

      button.addEventListener('click', function () {
        var isOpen = row.classList.contains('is-open');
        s.override = !isOpen;
        // Al abrir una fila con tap/click, cierro las demás (menos la del video: al colapsar
        // su panel la lista saltaría y la fila recién abierta se cerraría sola)
        if (!isOpen) {
          rows.forEach(function (other) {
            if (other === row || other.hasAttribute('data-sticky')) return;
            var o = state.get(other);
            o.override = null;
            o.hover = false;
            sync(other);
          });
        }
        sync(row);
      });
    });
  }

  /* ---------- 5. Formulario de contacto (webhook de la automatización) ---------- */
  function initForm() {
    var form = document.querySelector('[data-form]');
    if (!form) return;

    var submit = form.querySelector('[data-submit]');
    var submitLabel = form.querySelector('[data-submit-label]');
    var status = form.querySelector('[data-status]');
    var success = document.querySelector('[data-success]');
    var successEmail = document.querySelector('[data-success-email]');
    var resetBtn = document.querySelector('[data-reset]');
    var whatsapp = document.querySelector('[data-whatsapp]');
    var LABEL = submitLabel.textContent;
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

    // Mensajes: qué falló y cómo arreglarlo
    var rules = {
      nombre: function (v) {
        return v.trim() ? '' : 'Escribí tu nombre, así sabemos cómo llamarte.';
      },
      email: function (v) {
        if (!v.trim()) return 'Escribí tu email para que podamos responderte.';
        if (!EMAIL_RE.test(v.trim())) return 'Revisá el email: parece que le falta algo (ej.: nombre@gmail.com).';
        return '';
      },
      tipo: function (v) {
        return v ? '' : 'Elegí un tipo de proyecto. Si no estás seguro, elegí «Todavía no lo sé».';
      },
      mensaje: function (v) {
        if (!v.trim()) return 'Contanos un poco de tu idea: con dos o tres líneas alcanza.';
        if (v.trim().length < 10) return 'Contanos un poco más: con dos o tres líneas alcanza.';
        return '';
      }
    };

    function showError(input, message) {
      var field = input.closest('.field');
      var error = document.getElementById(input.id + '-error');
      field.classList.toggle('is-invalid', !!message);
      input.setAttribute('aria-invalid', message ? 'true' : 'false');
      error.textContent = message;
      error.hidden = !message;
    }

    function validate(input) {
      var rule = rules[input.name];
      if (!rule) return true;
      var message = rule(input.value);
      showError(input, message);
      return !message;
    }

    function fields() {
      return Object.keys(rules).map(function (name) { return form.elements[name]; });
    }

    // Validación al salir del campo; si ya tenía error, se revalida mientras escribe
    fields().forEach(function (input) {
      input.addEventListener('blur', function () {
        if (input.value || input.getAttribute('aria-invalid') === 'true') validate(input);
      });
      input.addEventListener('input', function () {
        if (input.getAttribute('aria-invalid') === 'true') validate(input);
      });
      input.addEventListener('change', function () {
        if (input.tagName === 'SELECT') validate(input);
      });
    });

    function setSending(sending) {
      form.classList.toggle('is-sending', sending);
      submit.disabled = sending;
      form.setAttribute('aria-busy', String(sending));
      submitLabel.textContent = sending ? 'Enviando tu idea' : LABEL;
    }

    function showStatus(html) {
      status.innerHTML = html;
    }

    function waLink(text) {
      return whatsapp ? ' o <a href="' + whatsapp.href + '" target="_blank" rel="noopener">' + (text || 'escribinos por WhatsApp') + '</a>' : '';
    }

    function igLink() {
      return '<a href="https://www.instagram.com/vezza.dev/" target="_blank" rel="noopener">escribinos por Instagram</a>';
    }

    function showSuccess(email) {
      successEmail.textContent = email || 'tu email';
      form.hidden = true;
      success.hidden = false;
      success.focus();
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      showStatus('');

      // 1) Validación
      var invalid = fields().filter(function (input) { return !validate(input); });
      if (invalid.length) {
        invalid[0].focus();
        return;
      }

      var email = form.elements.email.value.trim();

      // 2) Honeypot: si un bot completó el campo oculto, simulo éxito y no envío nada
      if (form.elements.website && form.elements.website.value) {
        showSuccess(email);
        return;
      }

      // 3) Formulario todavía sin configurar
      if (form.getAttribute('action').indexOf('[[') !== -1) {
        showStatus('El formulario todavía no está conectado. Mientras tanto, escribinos por <a href="https://www.instagram.com/vezza.dev/" target="_blank" rel="noopener">Instagram</a>' + waLink('por WhatsApp') + '.');
        return;
      }

      // 4) Envío
      setSending(true);
      fetch(form.action, {
        method: 'POST',
        // urlencoded = petición simple (sin preflight CORS); n8n y Make lo leen como campos
        body: new URLSearchParams(new FormData(form)),
        headers: { Accept: 'application/json' }
      })
        .then(function (res) {
          if (res.ok) {
            form.reset();
            showSuccess(email);
            return;
          }
          return res.json().catch(function () { return {}; }).then(function (data) {
            var detail = data && data.errors && data.errors.length
              ? ' (' + data.errors.map(function (er) { return er.message; }).join(', ') + ')'
              : '';
            showStatus('No se pudo enviar tu idea por un problema del servidor' + detail + '. Probá de nuevo en unos minutos o ' + igLink() + '.');
          });
        })
        .catch(function () {
          showStatus('No se pudo enviar tu idea. Revisá tu conexión a internet y probá de nuevo, o ' + igLink() + '.');
        })
        .then(function () { setSending(false); });
    });

    // "Enviar otra idea"
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        success.hidden = true;
        form.hidden = false;
        fields().forEach(function (input) { showError(input, ''); });
        form.elements.nombre.focus();
      });
    }
  }

  /* ---------- 6. VEZZA Shop: el video que corresponde al ancho ----------
     Es un reproductor común, con los controles del navegador (play, tiempo,
     pantalla completa). El CSS decide el formato (--shop-format en
     .shop__frame: 9x16, 4x5, 1x1 o 16x9) y acá se elige el archivo; el <video>
     trae preload="none", así que no baja nada hasta que se reproduce.
     Arranca solo, mudo, cuando se ve (salvo con "reducir movimiento" o ahorro
     de datos: ahí queda el póster y la persona toca play). En pantalla completa
     elige el formato más parecido a la forma de la pantalla.               */
  function initShopVideo() {
    var frame = document.querySelector('[data-shop] .shop__frame');
    var video = frame && frame.querySelector('[data-shop-video]');
    if (!video) return;

    var RATIOS = { '9x16': 9 / 16, '4x5': 4 / 5, '1x1': 1, '16x9': 16 / 9 };   // ancho / alto de cada video
    var version = frame.getAttribute('data-version') || '1';
    var current = '';         // formato del póster / video que tiene el <video>
    var userPaused = false;   // la persona lo pausó con los controles
    var autoPaused = false;   // se pausó solo porque salió de pantalla
    var codePause = false;    // pausa pedida por este script (para no confundirla con la de la persona)
    var lastVisible = true;   // si el marco se ve (sin IntersectionObserver se da por visto)

    function isFullscreen() {
      return (document.fullscreenElement || document.webkitFullscreenElement) === video;
    }
    // El formato cuya forma más se parece a la de la pantalla
    function formatForScreen() {
      var ratio = window.innerWidth / window.innerHeight;
      var best = '16x9';
      var bestGap = Infinity;
      Object.keys(RATIOS).forEach(function (fmt) {
        var gap = Math.abs(Math.log(ratio / RATIOS[fmt]));
        if (gap < bestGap) { best = fmt; bestGap = gap; }
      });
      return best;
    }
    function currentFormat() {
      // En pantalla completa manda la forma de la pantalla; si no, el corte de ancho del CSS
      if (isFullscreen()) return formatForScreen();
      return getComputedStyle(frame).getPropertyValue('--shop-format').trim() || '16x9';
    }
    function fileFor(fmt, ext) {
      return 'assets/video/vezza-shop-' + fmt + '.' + ext + '?v=' + version;
    }
    function autoplayAllowed() {
      var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var saveData = navigator.connection && navigator.connection.saveData;
      return !reduce && !saveData;
    }
    function play() {
      var promise = video.play();
      // Si el navegador bloquea el autoplay (ahorro de batería, por ejemplo), queda el póster con sus controles
      if (promise && promise.catch) promise.catch(function () {});
    }
    function pause() {
      codePause = true;
      video.pause();
    }

    // Cambia de formato: sigue en el mismo segundo (los cuatro duran lo mismo) y, si estaba reproduciendo, sigue
    function setFormat(fmt) {
      var time = video.currentTime;
      var resume = !video.paused;
      current = fmt;
      video.poster = fileFor(fmt, 'jpg');
      video.src = fileFor(fmt, 'mp4');
      if (time) {
        video.addEventListener('loadedmetadata', function seek() {
          video.removeEventListener('loadedmetadata', seek);
          video.currentTime = time;
        });
      }
      if (resume) play();
    }
    function syncFormat() {
      var fmt = currentFormat();
      if (fmt !== current) setFormat(fmt);
    }

    // Sin JS el HTML ya es un reproductor común con el video 16:9; acá se ajusta al ancho
    current = '16x9';
    syncFormat();

    // La persona pausa o reproduce con los controles del navegador
    video.addEventListener('play', function () { userPaused = false; autoPaused = false; });
    video.addEventListener('pause', function () {
      if (codePause) { codePause = false; return; }
      userPaused = true;
    });

    // Mientras no se ve, se pausa; al volver sigue, salvo que la persona lo haya pausado.
    // En pantalla completa no se toca: el marco puede quedar fuera de la vista sin que importe.
    function applyVisibility(visible) {
      if (isFullscreen()) return;
      if (!visible) {
        if (!video.paused) { autoPaused = true; pause(); }
      } else if (autoPaused && !userPaused && autoplayAllowed()) {
        autoPaused = false;
        play();
      }
    }

    function onFullscreenChange() {
      if (!isFullscreen()) applyVisibility(lastVisible);   // al salir, si el marco quedó fuera de la vista, se pausa
      syncFormat();
    }
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('webkitfullscreenchange', onFullscreenChange);

    // Cambio de ancho (rotar el celular, achicar la ventana): otro formato
    var resizing = false;
    window.addEventListener('resize', function () {
      if (resizing) return;
      resizing = true;
      requestAnimationFrame(function () {
        resizing = false;
        syncFormat();
      });
    }, { passive: true });

    function start() {
      if (autoplayAllowed()) play();
    }

    if (!('IntersectionObserver' in window)) { start(); return; }

    // Arranca cuando se ve y se queda a la vista un instante (300 ms), una sola vez.
    // Así no baja nada si la persona solo pasa de largo, por ejemplo con el scroll
    // suave de "Contanos tu idea" hasta el contacto.
    var dwell = 0;
    var near = new IntersectionObserver(function (entries) {
      clearTimeout(dwell);
      if (!entries[entries.length - 1].isIntersecting) return;
      dwell = setTimeout(function () { near.disconnect(); start(); }, 300);
    });
    near.observe(frame);

    new IntersectionObserver(function (entries) {
      lastVisible = entries[entries.length - 1].isIntersecting;
      applyVisibility(lastVisible);
    }).observe(frame);
  }

  initHeaderState();
  initMenu();
  initActiveLink();
  initServices();
  initForm();
  initShopVideo();
})();
