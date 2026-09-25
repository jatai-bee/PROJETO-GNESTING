/*
 * G-Nesting — interações da vitrine (melhoria progressiva: tudo funciona sem JS).
 * - [data-autosubmit]: envia o formulário ao mudar (ordenação, quantidade no carrinho)
 * - [data-gallery]: miniaturas trocam a foto principal sem recarregar
 */
(function () {
  'use strict';

  document.documentElement.classList.add('js');

  document.addEventListener('change', function (event) {
    var field = event.target;
    if (field instanceof HTMLElement && field.hasAttribute('data-autosubmit') && field.form) {
      if (field.type === 'number' && !field.checkValidity()) {
        field.form.reportValidity();
        return;
      }
      field.form.requestSubmit ? field.form.requestSubmit() : field.form.submit();
    }
  });

  document.addEventListener('click', function (event) {
    var thumb = event.target instanceof Element ? event.target.closest('[data-gallery-thumb]') : null;
    if (!thumb) {
      return;
    }
    var gallery = thumb.closest('[data-gallery]');
    var main = gallery ? gallery.querySelector('[data-gallery-main]') : null;
    if (!main) {
      return;
    }
    event.preventDefault();
    main.src = thumb.getAttribute('data-src');
    main.srcset = thumb.getAttribute('data-srcset');
    main.alt = thumb.getAttribute('data-alt');
    gallery.querySelectorAll('[data-gallery-thumb]').forEach(function (item) {
      item.setAttribute('aria-current', item === thumb ? 'true' : 'false');
    });
  });
})();
