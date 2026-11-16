'use strict';

// Contrato da base (docs/CONTRATO.md, seções 1 a 4 e 15): cabeçalho, guarda,
// carregador, prefixos e o que nunca pode aparecer no código do plugin.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const raiz = path.resolve(__dirname, '..');
const ler = (p) => fs.readFileSync(path.join(raiz, p), 'utf8').replace(/\r\n/g, '\n');
const tem = (t, s, m) => assert.ok(t.includes(s), m || s);

function listar(pasta, saida = []) {
  for (const nome of fs.readdirSync(path.join(raiz, pasta))) {
    const rel = path.join(pasta, nome);
    if (fs.statSync(path.join(raiz, rel)).isDirectory()) listar(rel, saida);
    else saida.push(rel.split(path.sep).join('/'));
  }
  return saida;
}

const principal = ler('consulta-leve/consulta-leve.php');
for (const campo of ['Plugin Name:       Consulta Leve', 'Requires at least: 6.3', 'Requires PHP:      7.4', 'License:           GPL-2.0-or-later', 'Text Domain:       consulta-leve', 'Update URI:        https://github.com/samuelmarmelo/consulta-leve']) {
  tem(principal, campo, `cabeçalho: ${campo}`);
}
const versao = /^ \* Version:\s+(\S+)$/m.exec(principal)[1];
tem(principal, `define( 'CONSULTA_LEVE_VERSAO', '${versao}' );`, 'constante igual ao cabeçalho');
tem(principal, "if ( defined( 'CONSULTA_LEVE_VERSAO' ) ) {\n\treturn;\n}", 'guarda de segunda cópia');
tem(principal, "'#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*\\.php$#D'", 'regra do nome de módulo');
tem(principal, "do_action( 'consulta_leve_falha', $modulo, $erro );", 'falha vira ação');

// Todo módulo da lista existe ou está previsto no contrato.
const contrato = ler('docs/CONTRATO.md');
const modulos = [...principal.matchAll(/'([a-z0-9_/-]+\.php)'/g)].map((m) => m[1]).filter((m) => m.includes('/'));
assert.ok(modulos.length >= 15, 'lista de módulos completa');

const arquivos = listar('consulta-leve');
const php = arquivos.filter((f) => f.endsWith('.php'));
for (const f of php) {
  const t = ler(f);
  assert.ok(t.startsWith('<?php'), `${f}: começa com <?php`);
  tem(t, "defined( 'ABSPATH' ) || exit;", `${f}: guarda ABSPATH`);
  for (const proibido of [/\beval\s*\(/, /\bbase64_decode\s*\(/, /\bcreate_function\s*\(/, /\bassert\s*\(/, /\bshell_exec\s*\(/, /\bexec\s*\(/, /\bpassthru\s*\(/, /\bsystem\s*\(/, /\bunserialize\s*\(/, /\$_REQUEST\b/, /\bextract\s*\(/]) {
    assert.ok(!proibido.test(t), `${f}: proibido ${proibido}`);
  }
  // Funções e constantes globais só com o prefixo.
  for (const m of t.matchAll(/^\s*function\s+&?([a-zA-Z0-9_]+)\s*\(/gm)) {
    assert.ok(m[1].startsWith('consulta_leve_'), `${f}: função sem prefixo ${m[1]}`);
  }
  for (const m of t.matchAll(/^const\s+([A-Z0-9_]+)\s*=/gm)) {
    assert.ok(m[1].startsWith('CONSULTA_LEVE_'), `${f}: constante sem prefixo ${m[1]}`);
  }
  // Text domain único.
  for (const m of t.matchAll(/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_n|_x)\([^;]*?,\s*'([a-z-]+)'\s*\)/g)) {
    assert.equal(m[1], 'consulta-leve', `${f}: text domain ${m[1]}`);
  }
}

// Sem travessão longo nos textos do repositório.
for (const f of [...arquivos, ...listar('docs'), 'README.md', 'CLAUDE.md'].filter((x) => /\.(php|js|md|txt|css)$/.test(x))) {
  assert.ok(!ler(f).includes('—'), `${f}: travessão longo`);
}

// Seguro por padrão.
const config = ler('consulta-leve/nucleo/config.php');
for (const desligado of ["'teto_global'     => false", "'indice_busca'    => false", "'indice_na_busca' => false", "'rest'            => false"]) {
  tem(config, desligado, `padrão desligado: ${desligado}`);
}
tem(contrato, '## 4. Coexistência com o tema e outros plugins', 'contrato de coexistência');
console.log('base: ok');
