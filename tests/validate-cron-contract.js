'use strict';

// Contrato do cron real (docs/CONTRATO.md, seção 10.4): desligado por padrão,
// nenhum arquivo do servidor tocado, constante só com a guarda e escrita no
// painel só com nonce e permissão.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const raiz = path.resolve(__dirname, '..');
const ler = (p) => fs.readFileSync(path.join(raiz, p), 'utf8').replace(/\r\n/g, '\n');
const tem = (t, s, m) => assert.ok(t.includes(s), m || s);

const cron = ler('consulta-leve/cron/cron.php');
assert.ok(!/file_put_contents|fopen|fwrite|WP_Filesystem|insert_with_markers|rename\s*\(|copy\s*\(/.test(cron), 'cron.php não escreve arquivo');
assert.equal((cron.match(/define\( 'DISABLE_WP_CRON'/g) || []).length, 1, 'DISABLE_WP_CRON definida num lugar só');
tem(cron, "if ( defined( 'DISABLE_WP_CRON' ) || 'ativo' !== consulta_leve_cron_estado( consulta_leve_cron_situacao() ) ) {\n\t\t\treturn false;\n\t\t}", 'guarda da constante');
tem(cron, 'consulta_leve_atualizador_plugin_proprio()', 'mesma conferência de plugin próprio do atualizador');
assert.ok(!/consulta_leve_opcao\(\s*'|consulta_leve_config\(\)/.test(cron), 'decisão não cria o memo da configuração na carga');
for (const limite of ['CONSULTA_LEVE_CRON_GRAVAR_S = 300;', 'CONSULTA_LEVE_CRON_LIGAR_S = 1800;', 'CONSULTA_LEVE_CRON_VENCE_S = 3600;']) tem(cron, limite);

const principal = ler('consulta-leve/consulta-leve.php');
assert.ok(principal.indexOf("'cron/cron.php'") > principal.indexOf("'atualizacao/atualizador.php'"), 'cron carrega depois do atualizador');
assert.ok(principal.indexOf("'cron/cron.php'") < principal.indexOf('is_admin()'), 'cron carrega em toda requisição');

const config = ler('consulta-leve/nucleo/config.php');
tem(config, "'cron_real'       => false", 'padrão desligado');
assert.match(config, /function consulta_leve_config_chaves_proprias\(\): array \{\s+return array\([^)]*'cron_real'/, 'chave fora do formulário geral');

const admin = ler('consulta-leve/admin/admin.php');
tem(admin, "function consulta_leve_admin_cron(): void {\n\t\tconsulta_leve_admin_conferir( 'cron' );", 'nonce e permissão antes de tudo');
tem(admin, "consulta_leve_registrar( 'cron_real', $operacao, $resultado );", 'ação registrada');
tem(admin, 'consulta_leve_config_do_formulario( $recebido )', 'formulário geral pula as chaves próprias');
console.log('cron: ok');
