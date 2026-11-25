'use strict';

// Contrato dos cabeçalhos de segurança (docs/CONTRATO.md, seção 15.1):
// desligados por padrão, só por PHP, sem trocar o que já existe e com
// escrita no painel só com nonce e permissão.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const raiz = path.resolve(__dirname, '..');
const ler = (p) => fs.readFileSync(path.join(raiz, p), 'utf8').replace(/\r\n/g, '\n');
const tem = (t, s, m) => assert.ok(t.includes(s), m || s);

const cab = ler('consulta-leve/seguranca/cabecalhos.php');
assert.ok(!/file_put_contents|fopen|fwrite|WP_Filesystem|insert_with_markers|save_mod_rewrite_rules|flush_rewrite_rules/.test(cab), 'nenhum arquivo do servidor tocado');
assert.equal((cab.match(/[^_]header\(/g) || []).length, 1, 'um ponto de envio só');
tem(cab, "header( $nome . ': ' . $valor, false );", 'nunca troca cabeçalho existente');
tem(cab, 'headers_list()', 'confere o que já está na resposta');
tem(cab, "if ( is_admin() || headers_sent() || consulta_leve_dispensado( 'cabecalhos' ) ) {", 'só no site e com dispensa');
assert.ok(!/X-XSS-Protection|interest-cohort/.test(cab), 'cabeçalhos obsoletos ficam de fora');

const config = ler('consulta-leve/nucleo/config.php');
for (const chave of ['cab_nosniff', 'cab_referrer', 'cab_frame', 'cab_upgrade']) {
  assert.match(config, new RegExp(`'${chave}'\\s+=> false,`), `padrão desligado: ${chave}`);
  assert.match(config, new RegExp(`chaves_proprias\\(\\): array \\{[^}]*'${chave}'`), `fora do formulário geral: ${chave}`);
}
assert.match(config, /'cab_hsts'\s+=> 'nao',/, 'HSTS desligado');
assert.match(config, /'cab_hsts'\s+=> array\( 'escolha', array\( 'nao', 'ano', 'ano_preload' \) \),/, 'HSTS de lista fechada');
assert.match(config, /chaves_proprias\(\): array \{[^}]*'cab_hsts'/, 'HSTS fora do formulário geral');

const admin = ler('consulta-leve/admin/admin.php');
tem(admin, "function consulta_leve_admin_cabecalhos(): void {\n\t\tconsulta_leve_admin_conferir( 'cabecalhos' );", 'nonce e permissão antes de tudo');
tem(admin, "wp_nonce_field( 'consulta_leve_cabecalhos' );", 'nonce no formulário');
tem(admin, 'name="confirmar_hsts"', 'confirmação extra do HSTS');

const coexistencia = ler('consulta-leve/nucleo/coexistencia.php');
assert.match(coexistencia, /metodos_dispensaveis\(\): array \{\s+return array\([^)]*'cabecalhos'/, 'tema pode dispensar');
console.log('cabecalhos: ok');
