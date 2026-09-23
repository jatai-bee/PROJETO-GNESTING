/*
 * G-Nesting — painel administrativo.
 * Melhorias progressivas: tudo funciona sem JavaScript; aqui só há conveniências.
 */
(function () {
  'use strict';

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

  // Ao voltar pelo histórico, a página pode vir do cache com botões desabilitados
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      document.querySelectorAll('button[disabled]').forEach(function (b) { b.disabled = false; });
    }
  });
})();
