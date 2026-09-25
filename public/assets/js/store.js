/*
 * G-Nesting — interações da vitrine (melhoria progressiva: tudo funciona sem JS).
 * - [data-autosubmit]: envia o formulário ao mudar (ordenação, quantidade no carrinho)
 * - [data-gallery]: miniaturas trocam a foto principal sem recarregar
 * - [data-variant-select]: troca de variação atualiza preço, SKU, medidas e estoque exibidos
 */
(function () {
  'use strict';

  document.documentElement.classList.add('js');

  // Troca de variação: preço, SKU, medidas e disponibilidade vêm do data-variant da opção
  // (o servidor continua sendo a fonte do preço: o carrinho recalcula tudo).
  function applyVariant(select) {
    var option = select.options[select.selectedIndex];
    var scope = select.closest('[data-variant-scope]');
    if (!option || !scope) {
      return;
    }
    var data;
    try {
      data = JSON.parse(option.getAttribute('data-variant'));
    } catch (e) {
      return;
    }
    scope.querySelectorAll('[data-field]').forEach(function (el) {
      var key = el.getAttribute('data-field');
      if (Object.prototype.hasOwnProperty.call(data, key)) {
        el.textContent = data[key];
      }
    });
    scope.querySelectorAll('[data-show]').forEach(function (el) {
      el.hidden = !data[el.getAttribute('data-show')];
    });
    var qty = scope.querySelector('[data-field-max]');
    if (qty) {
      qty.max = data.max;
      if (Number(qty.value) > data.max) {
        qty.value = data.max;
      }
    }
    var buy = scope.querySelector('[data-buy]');
    if (buy) {
      buy.disabled = !data.in_stock;
    }
  }

  document.addEventListener('change', function (event) {
    if (event.target instanceof HTMLSelectElement && event.target.hasAttribute('data-variant-select')) {
      applyVariant(event.target);
    }
  });

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
