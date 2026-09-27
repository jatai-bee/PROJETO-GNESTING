/*
 * G-Nesting — interações da vitrine (melhoria progressiva: tudo funciona sem JS).
 * - [data-autosubmit]: envia o formulário ao mudar (ordenação, quantidade no carrinho)
 * - [data-gallery]: miniaturas trocam a foto principal sem recarregar
 * - [data-variant-select]: troca de variação atualiza preço, SKU, medidas e estoque exibidos
 * - [data-stepper]: botões − e + da quantidade
 * - [data-share]: compartilhar (menu do celular) ou copiar o link
 * - .filters: no computador o painel de filtros fica sempre aberto
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
    var line = scope.querySelector('[data-stock-line]');
    if (line && data.stock_class) {
      line.className = 'stock-line stock-line--' + data.stock_class;
      line.lastChild.textContent = ' ' + ({ ok: 'Pronta entrega', out: 'Esgotado', order: 'Sob encomenda' })[data.stock_class];
    }
  }

  // Quantidade: − e + respeitam min/max do campo e disparam "change" (o carrinho envia sozinho)
  document.addEventListener('click', function (event) {
    var button = event.target instanceof Element ? event.target.closest('[data-step]') : null;
    var stepper = button ? button.closest('[data-stepper]') : null;
    var input = stepper ? stepper.querySelector('input[type="number"]') : null;
    if (!input) {
      return;
    }
    var min = Number(input.min || 1);
    var max = input.max === '' ? Infinity : Number(input.max);
    var next = Math.min(max, Math.max(min, (Number(input.value) || min) + Number(button.getAttribute('data-step'))));
    if (next !== Number(input.value)) {
      input.value = next;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });

  // Compartilhar: menu nativo quando existe; senão copia o link
  document.querySelectorAll('[data-share]').forEach(function (button) {
    if (!navigator.share && !(navigator.clipboard && window.isSecureContext)) {
      return;
    }
    button.hidden = false;
    button.addEventListener('click', function () {
      var url = button.getAttribute('data-share-url') || window.location.href;
      if (navigator.share) {
        navigator.share({ title: button.getAttribute('data-share-title') || document.title, url: url }).catch(function () {});
        return;
      }
      navigator.clipboard.writeText(url).then(function () {
        var label = button.querySelector('[data-share-label]');
        if (label) {
          label.textContent = 'Link copiado!';
          setTimeout(function () { label.textContent = 'Compartilhar'; }, 2500);
        }
      });
    });
  });

  // Filtros: sempre abertos no computador (no celular ficam na gaveta "Filtrar")
  var filters = document.querySelector('details.filters');
  if (filters && window.matchMedia) {
    var wide = window.matchMedia('(min-width: 1024px)');
    var syncFilters = function () {
      if (wide.matches) {
        filters.open = true;
      }
    };
    syncFilters();
    if (wide.addEventListener) {
      wide.addEventListener('change', syncFilters);
    }
  }

  // ---- Checkout ----------------------------------------------------------
  // Sem JavaScript tudo funciona pelo botão "Calcular frete" (servidor). Com JS:
  // cotação ao sair do CEP, endereço salvo esconde o formulário, total atualizado.
  var checkout = document.querySelector('[data-checkout]');
  if (checkout) {
    var money = function (cents) {
      return 'R$ ' + (cents / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    };
    var updateTotal = function () {
      var chosen = checkout.querySelector('input[name="shipping_code"]:checked');
      var total = checkout.querySelector('[data-summary-total]');
      var shipping = checkout.querySelector('[data-summary-shipping]');
      if (!chosen || !total || !shipping) {
        return;
      }
      shipping.textContent = chosen.getAttribute('data-price');
      total.textContent = money(Number(total.getAttribute('data-subtotal')) + Number(chosen.getAttribute('data-price-cents')));
    };
    var addressForm = checkout.querySelector('[data-address-form]');
    var syncAddress = function () {
      var picked = checkout.querySelector('input[name="address_id"]:checked');
      if (addressForm && picked) {
        addressForm.hidden = picked.value !== '';
      }
    };
    var quote = function (zip) {
      var digits = (zip || '').replace(/\D/g, '');
      var box = checkout.querySelector('[data-shipping-options]');
      if (digits.length !== 8 || !box) {
        return;
      }
      var body = new URLSearchParams();
      body.set('cep', digits);
      fetch(checkout.getAttribute('action').replace(/\/checkout$/, '/api/frete/cotar'), {
        method: 'POST',
        headers: { 'X-CSRF-Token': checkout.querySelector('input[name="_token"]').value, 'Accept': 'application/json' },
        body: body
      }).then(function (r) { return r.json(); }).then(function (data) {
        box.textContent = '';
        if (!data.options || data.options.length === 0) {
          var p = document.createElement('p');
          p.className = 'muted';
          p.textContent = data.message || 'Não entregamos neste CEP pelas opções automáticas.';
          box.appendChild(p);
          return;
        }
        var list = document.createElement('fieldset');
        list.className = 'choice-list';
        data.options.forEach(function (o, i) {
          var label = document.createElement('label');
          label.className = 'choice';
          var input = document.createElement('input');
          input.type = 'radio';
          input.name = 'shipping_code';
          input.value = o.code;
          input.checked = i === 0;
          input.setAttribute('data-price-cents', o.price_cents);
          input.setAttribute('data-price', o.price_cents === 0 ? 'Grátis' : o.price);
          var row = document.createElement('span');
          row.className = 'choice__row';
          var name = document.createElement('span');
          name.innerHTML = '<strong></strong><br><small class="muted"></small>';
          name.querySelector('strong').textContent = o.service;
          name.querySelector('small').textContent = o.days > 0 ? 'até ' + o.days + ' dias úteis após a produção' : 'combine a retirada após a produção';
          var price = document.createElement('strong');
          price.textContent = o.price_cents === 0 ? 'Grátis' : o.price;
          row.appendChild(name);
          row.appendChild(price);
          label.appendChild(input);
          label.appendChild(row);
          list.appendChild(label);
        });
        box.appendChild(list);
        checkout.querySelector('[data-quoted-zip]').value = digits;
        var uf = checkout.querySelector('#state');
        if (uf && uf.value === '' && data.state) {
          uf.value = data.state; // UF do CEP (o servidor confere de novo)
        }
        updateTotal();
      }).catch(function () { /* o botão "Calcular frete" continua disponível */ });
    };

    checkout.addEventListener('change', function (event) {
      var t = event.target;
      if (t.name === 'shipping_code') {
        updateTotal();
      } else if (t.name === 'address_id') {
        syncAddress();
        quote(t.value !== '' ? t.getAttribute('data-address-zip') : checkout.querySelector('#zip_code').value);
      } else if (t.id === 'zip_code') {
        quote(t.value);
      }
    });
    syncAddress();
    updateTotal();
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
