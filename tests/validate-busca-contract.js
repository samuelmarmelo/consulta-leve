'use strict';

// Contrato da busca rápida e da REST (docs/CONTRATO.md, seções 8 e 9).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const raiz = path.resolve(__dirname, '..');
const ler = (p) => fs.readFileSync(path.join(raiz, p), 'utf8').replace(/\r\n/g, '\n');
const tem = (t, s, m) => assert.ok(t.includes(s), m || s);

const js = ler('consulta-leve/assets/js/busca.js');
assert.ok(!/innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function/.test(js), 'busca.js: nada de HTML vindo de texto');
tem(js, 'textContent', 'resultado por textContent');
tem(js, 'new AbortController()', 'cancela o pedido anterior');
tem(js, 'var ESPERA_MS = 300;', 'espera de 300 ms');
tem(js, "credentials: 'omit'", 'pedido público sem cookie');
tem(js, "u.protocol === 'https:' || u.protocol === 'http:'", 'link só http(s)');
tem(js, '(function () {', 'IIFE');

const rest = ler('consulta-leve/rest/rest.php');
tem(rest, "'maximum' => 20,", 'por_pagina com teto 20');
tem(rest, "'pattern'   => '^[0-9a-f.]*$',", 'cursor só hex');
tem(rest, "'publish' !== $post->post_status || '' !== (string) $post->post_password", 'confere status e senha no post');
tem(rest, "'public' => true, 'exclude_from_search' => false", 'só tipos públicos e pesquisáveis');
tem(rest, "hash_equals( $etag, $item )", 'ETag comparada com hash_equals');
tem(rest, "if ( ! consulta_leve_ativo( 'rest' ) ) {", 'rota só com a REST ligada');

const busca = ler('consulta-leve/busca/busca.php');
tem(busca, "FILTER_VALIDATE_IP", 'IP validado');
tem(busca, "$q->set( 'post__in', array( 0 ) );", 'termo curto não vai ao banco');
tem(busca, 'array_intersect( $colunas, $mapa[ $escolha ] )', 'colunas por interseção');
console.log('busca: ok');
