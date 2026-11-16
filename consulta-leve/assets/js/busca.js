/**
 * Busca rápida da Consulta Leve (contrato, seção 8.2).
 *
 * Componente independente (SafeWidget): sem este script, o formulário
 * continua sendo uma busca comum do WordPress. Espera 300 ms depois da
 * última tecla, só pergunta com o mínimo de letras, cancela o pedido
 * anterior (AbortController) e monta o resultado só com textContent.
 * Origem: CRM interno assets/js/busca.js e loja C cabecalho.js.
 */
(function () {
  'use strict';

  var ESPERA_MS = 300;

  function safe(fn) {
    try {
      fn();
    } catch (erro) {
      if (window.console && console.warn) console.warn('[consulta-leve] busca rápida desligada:', erro);
    }
  }

  function linkSeguro(url) {
    try {
      var u = new URL(url, window.location.href);
      return u.protocol === 'https:' || u.protocol === 'http:' ? u.href : '';
    } catch (e) {
      return '';
    }
  }

  function montar(form) {
    var endpoint = form.getAttribute('data-cl-busca');
    var campo = form.querySelector('.cl-busca__campo');
    var lista = form.querySelector('.cl-busca__lista');
    var aviso = form.querySelector('.cl-busca__aviso');
    if (!endpoint || !campo || !lista || !aviso || !window.fetch || !window.AbortController) return;

    var minimo = Math.max(2, parseInt(form.getAttribute('data-cl-min'), 10) || 2);
    var tipos = form.getAttribute('data-cl-tipos') || '';
    var limite = parseInt(form.getAttribute('data-cl-limite'), 10) || 8;
    var timer = 0;
    var pedido = null;
    var proximo = '';
    var termoAtual = '';

    function fechar() {
      lista.hidden = true;
      campo.setAttribute('aria-expanded', 'false');
    }

    function limpar() {
      while (lista.firstChild) lista.removeChild(lista.firstChild);
      proximo = '';
      fechar();
    }

    function mostrarAviso(texto) {
      aviso.textContent = texto;
    }

    function acrescentar(itens) {
      itens.forEach(function (item) {
        var href = linkSeguro(item.url);
        if (!href) return;
        var li = document.createElement('li');
        li.className = 'cl-busca__item';
        li.setAttribute('role', 'option');
        var a = document.createElement('a');
        a.href = href;
        a.className = 'cl-busca__link';
        a.textContent = String(item.titulo || '');
        li.appendChild(a);
        lista.appendChild(li);
      });
    }

    function botaoMais() {
      var velho = lista.querySelector('.cl-busca__mais');
      if (velho) velho.parentNode.removeChild(velho);
      if (!proximo) return;
      var li = document.createElement('li');
      li.className = 'cl-busca__mais';
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = 'Mais resultados';
      b.addEventListener('click', function () {
        buscar(termoAtual, proximo);
      });
      li.appendChild(b);
      lista.appendChild(li);
    }

    function buscar(termo, cursor) {
      if (pedido) pedido.abort();
      pedido = new AbortController();
      var url = new URL(endpoint, window.location.href);
      url.searchParams.set('termo', termo);
      url.searchParams.set('por_pagina', String(limite));
      if (tipos) url.searchParams.set('tipos', tipos);
      if (cursor) url.searchParams.set('cursor', cursor);

      fetch(url.toString(), { signal: pedido.signal, credentials: 'omit', headers: { Accept: 'application/json' } })
        .then(function (r) {
          if (r.status === 429) throw new Error('limite');
          if (!r.ok) throw new Error('falha');
          return r.json();
        })
        .then(function (dados) {
          if (termo !== termoAtual) return;
          if (!cursor) limpar();
          var itens = dados && Array.isArray(dados.itens) ? dados.itens : [];
          proximo = dados && typeof dados.proximo === 'string' ? dados.proximo : '';
          acrescentar(itens);
          botaoMais();
          var total = lista.querySelectorAll('.cl-busca__item').length;
          lista.hidden = total === 0;
          campo.setAttribute('aria-expanded', total ? 'true' : 'false');
          mostrarAviso(total ? total + (total === 1 ? ' resultado' : ' resultados') : 'Nada encontrado.');
        })
        .catch(function (erro) {
          if (erro && erro.name === 'AbortError') return;
          mostrarAviso(erro && erro.message === 'limite' ? 'Muitas buscas seguidas. Espere um minuto.' : 'A busca rápida falhou. Use o botão Buscar.');
        });
    }

    campo.addEventListener('input', function () {
      window.clearTimeout(timer);
      var termo = campo.value.replace(/\s+/g, ' ').trim();
      termoAtual = termo;
      if (termo.replace(/ /g, '').length < minimo) {
        if (pedido) pedido.abort();
        limpar();
        mostrarAviso('');
        return;
      }
      timer = window.setTimeout(function () {
        buscar(termo, '');
      }, ESPERA_MS);
    });

    form.addEventListener('keydown', function (e) {
      var links = Array.prototype.slice.call(lista.querySelectorAll('.cl-busca__link, .cl-busca__mais button'));
      var i = links.indexOf(document.activeElement);
      if (e.key === 'Escape') {
        fechar();
        campo.focus();
      } else if (e.key === 'ArrowDown' && links.length) {
        e.preventDefault();
        links[Math.min(links.length - 1, i + 1)].focus();
      } else if (e.key === 'ArrowUp' && links.length) {
        e.preventDefault();
        if (i <= 0) campo.focus();
        else links[i - 1].focus();
      }
    });

    document.addEventListener('click', function (e) {
      if (!form.contains(e.target)) fechar();
    });
  }

  function iniciar() {
    Array.prototype.forEach.call(document.querySelectorAll('form[data-cl-busca]'), function (form) {
      safe(function () {
        montar(form);
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
