/*
 * G-Nesting — painel administrativo.
 * Melhorias progressivas: tudo funciona sem JavaScript; aqui só há conveniências.
 */
(function () {
  'use strict';

  // ---- Aplicativo no celular ----------------------------------------------------
  // Registra o service worker (só em https) e mostra "Instalar" quando o navegador permite.
  // No iPhone não existe o convite automático: aparece a instrução de Compartilhar > Tela de Início.
  (function () {
    var sw = document.body ? document.body.getAttribute('data-sw') : null;
    var secure = window.location.protocol === 'https:' || ['localhost', '127.0.0.1'].indexOf(window.location.hostname) !== -1;
    if (sw && secure && 'serviceWorker' in navigator) {
      window.addEventListener('load', function () {
        navigator.serviceWorker.register(sw).catch(function () { /* sem service worker a loja funciona igual */ });
      });
    }
    var installed = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
    if (installed) {
      return;
    }
    var boxes = document.querySelectorAll('[data-pwa-box]');
    var buttons = document.querySelectorAll('[data-pwa-install]');
    var reveal = function (selector) {
      boxes.forEach(function (box) { box.hidden = false; });
      document.querySelectorAll(selector).forEach(function (el) { el.hidden = false; });
    };
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (ios) {
      reveal('[data-pwa-ios]');
    }
    var deferred = null;
    window.addEventListener('beforeinstallprompt', function (event) {
      event.preventDefault();
      deferred = event;
      reveal('[data-pwa-install]');
    });
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        if (!deferred) {
          return;
        }
        deferred.prompt();
        deferred.userChoice.then(function () {
          deferred = null;
          buttons.forEach(function (b) { b.hidden = true; });
        });
      });
    });
    window.addEventListener('appinstalled', function () {
      boxes.forEach(function (box) { box.hidden = true; });
      buttons.forEach(function (b) { b.hidden = true; });
    });
  })();

  // Gavetas do menu (<details>): Esc fecha e devolve o foco ao botão que abriu
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
      return;
    }
    document.querySelectorAll('details.nav-drawer[open], details.admin-drawer[open]').forEach(function (drawer) {
      drawer.open = false;
      var summary = drawer.querySelector('summary');
      if (summary) {
        summary.focus();
      }
    });
  });

  // Confirmação antes de ações destrutivas: <form data-confirm="Mensagem">
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
      event.preventDefault();
    }
  });

  // Evita envio duplo (ex.: upload demorado): desabilita o botão após o envio
  document.addEventListener('submit', function (event) {
    if (event.defaultPrevented) {
      return;
    }
    var button = event.target.querySelector('button[type="submit"]');
    if (button) {
      window.setTimeout(function () { button.disabled = true; }, 0);
    }
  });

  // Botão de impressão (romaneio): <button data-print>
  document.addEventListener('click', function (event) {
    if (event.target instanceof Element && event.target.closest('[data-print]')) {
      window.print();
    }
  });

  // ---- Abas do formulário de produto --------------------------------------
  // Sem JavaScript as seções ficam uma embaixo da outra; aqui, uma por vez. O formulário é um só:
  // "Salvar" envia todas as abas. Com erro de validação, abre a aba do primeiro campo com erro.
  var tabForm = document.querySelector('[data-tabs]');
  if (tabForm) {
    var panels = tabForm.querySelectorAll('[data-tab-panel]');
    var links = document.querySelectorAll('[data-tab-link]');
    var show = function (name) {
      var found = false;
      panels.forEach(function (panel) {
        var on = panel.getAttribute('data-tab-panel') === name;
        panel.hidden = !on;
        found = found || on;
      });
      if (!found) {
        return false;
      }
      links.forEach(function (link) {
        if (link.getAttribute('data-tab-link') === name) {
          link.setAttribute('aria-current', 'page');
        } else {
          link.removeAttribute('aria-current');
        }
      });
      return true;
    };
    var invalid = tabForm.querySelector('.field--invalid, [aria-invalid="true"]');
    var start = invalid ? invalid.closest('[data-tab-panel]').getAttribute('data-tab-panel') : window.location.hash.slice(1);
    if (!show(start)) {
      show(panels[0].getAttribute('data-tab-panel'));
    }
    links.forEach(function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();
        var name = link.getAttribute('data-tab-link');
        show(name);
        history.replaceState(null, '', '#' + name);
      });
    });
    window.addEventListener('hashchange', function () { show(window.location.hash.slice(1)); });
  }

  // Margem ao vivo: (preço − custo) ÷ preço
  var marginBox = document.querySelector('[data-margin]');
  if (marginBox) {
    var parse = function (value) {
      var n = parseFloat(String(value || '').replace(/\./g, '').replace(',', '.'));
      return isNaN(n) ? null : Math.round(n * 100);
    };
    var brl = function (cents) {
      return 'R$ ' + (cents / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    };
    var updateMargin = function () {
      var price = parse(marginBox.querySelector('[name="price"]').value);
      var cost = parse(marginBox.querySelector('[name="cost"]').value);
      var value = marginBox.querySelector('[data-margin-value]');
      var note = marginBox.querySelector('[data-margin-note]');
      if (price === null || price <= 0 || cost === null) {
        value.textContent = '—';
        note.textContent = 'informe preço e custo';
        value.classList.remove('text-warn');
        return;
      }
      var pct = Math.round((price - cost) * 100 / price);
      value.textContent = pct + '%';
      note.textContent = brl(price - cost) + ' por unidade';
      value.classList.toggle('text-warn', pct < 20);
    };
    marginBox.addEventListener('input', updateMargin);
  }

  // Prévia do resultado no Google
  var serp = document.querySelector('[data-serp]');
  if (serp && tabForm) {
    var field = function (name) { return tabForm.querySelector('[name="' + name + '"]'); };
    var updateSerp = function () {
      serp.querySelector('[data-serp-title]').textContent = field('meta_title').value || ((field('name').value || 'Nome do produto') + ' | G-Nesting');
      serp.querySelector('[data-serp-desc]').textContent = field('meta_description').value || field('short_description').value || 'O resumo do produto aparece aqui.';
      if (field('slug').value) {
        serp.querySelector('[data-serp-slug]').textContent = field('slug').value;
      }
    };
    tabForm.addEventListener('input', updateSerp);
  }

  // Ao voltar pelo histórico, a página pode vir do cache com botões desabilitados
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      document.querySelectorAll('button[disabled]').forEach(function (b) { b.disabled = false; });
    }
  });
})();
