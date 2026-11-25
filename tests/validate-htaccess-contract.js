'use strict';

// Contrato dos cabeçalhos no .htaccess (docs/CONTRATO.md, seção 15.2):
// desligado por padrão, só o bloco próprio, só deste módulo, com cópia,
// conferência e volta; wp-config.php nunca.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const raiz = path.resolve(__dirname, '..');
const ler = (p) => fs.readFileSync(path.join(raiz, p), 'utf8').replace(/\r\n/g, '\n');
const tem = (t, s, m) => assert.ok(t.includes(s), m || s);

function listar(pasta, saida = []) {
  for (const nome of fs.readdirSync(path.join(raiz, pasta))) {
    const rel = `${pasta}/${nome}`;
    if (fs.statSync(path.join(raiz, rel)).isDirectory()) listar(rel, saida);
    else if (nome.endsWith('.php')) saida.push(rel);
  }
  return saida;
}

const ht = ler('consulta-leve/seguranca/htaccess.php');
const corpo = (nome) => {
  const inicio = ht.indexOf(`function ${nome}(`);
  assert.ok(inicio > 0, `função ${nome}`);
  const fim = ht.indexOf('\n\t}\n', inicio);
  return ht.slice(inicio, fim);
};

// Só este módulo escreve em .htaccess; ninguém escreve no wp-config.php.
for (const f of listar('consulta-leve')) {
  const t = ler(f);
  if (f !== 'consulta-leve/seguranca/htaccess.php') {
    assert.ok(!/(?:fopen|fwrite|file_put_contents|rename|unlink|ftruncate)\s*\([^;]*htaccess/i.test(t), `${f}: não escreve .htaccess`);
  }
  assert.ok(!/(?:fopen|fwrite|file_put_contents|rename|unlink|copy)\s*\([^;]*wp-config/i.test(t), `${f}: wp-config.php intocável`);
}
assert.ok(!/insert_with_markers|save_mod_rewrite_rules|flush_rewrite_rules|file_put_contents|wp-config/.test(ht), 'escrita própria, só no .htaccess');
assert.ok(!/\$_POST|\$_GET|\$_REQUEST|\$_COOKIE/.test(ht), 'nenhuma entrada do usuário no módulo');
assert.equal((ht.match(/\$_SERVER\[ '([A-Z_]+)' \]/g) || []).filter((s) => !/SERVER_SOFTWARE|HTTP_X_CONSULTA_LEVE_CONFERENCIA/.test(s)).length, 0, 'só dois campos do servidor');

// Bloco: marcadores, IfModule, lista fechada, condições.
tem(ht, "const CONSULTA_LEVE_HTACCESS_INICIO = '# BEGIN Consulta Leve';");
tem(ht, "const CONSULTA_LEVE_HTACCESS_FIM    = '# END Consulta Leve';");
const bloco = corpo('consulta_leve_htaccess_bloco');
tem(bloco, "'<IfModule mod_headers.c>'");
tem(bloco, 'consulta_leve_cabecalhos_lista( $config, true, array() )', 'só a lista fechada');
tem(bloco, "\"-z resp('\" . $nome . \"')\"", 'nunca troca nem repete');
tem(bloco, "\"%{HTTPS} == 'on' && \"", 'HSTS e CSP só em HTTPS');
assert.ok(!/setifempty|always|X-XSS-Protection|interest-cohort/.test(bloco), 'tabela onsuccess e sem cabeçalho obsoleto');

// Recusas e textos.
for (const motivo of ['embutida', 'permissao', 'multisite', 'servidor', 'bloqueado', 'dispensado', 'sem_escolha', 'caminho', 'link', 'nao_arquivo', 'tamanho', 'sem_leitura', 'sem_escrita', 'marcadores', 'marcador_wp', 'secao', 'continuacao', 'binario', 'mudou', 'bloco_sumiu', 'interrompido', 'volta_falhou']) {
  assert.match(corpo('consulta_leve_htaccess_motivos'), new RegExp(`'${motivo}'\\s+=> __\\(`), `texto: ${motivo}`);
}
for (const fato of ['is_link( $arquivo )', 'is_multisite()', 'DISALLOW_FILE_MODS', "current_user_can( 'manage_options' )"]) {
  tem(corpo('consulta_leve_htaccess_fatos'), fato);
}

// Ordem da escrita: cópia, base, hash de escrita, escrita, conferência, ativo.
const executar = corpo('consulta_leve_htaccess_executar');
const passos = ["'estado'   => 'pendente'", 'consulta_leve_htaccess_guardar_copia(', 'consulta_leve_htaccess_conferir_base(', "'sha_novo' => hash(", "consulta_leve_htaccess_escrever( $arquivo, $original, $novo['conteudo'] )", 'consulta_leve_htaccess_conferir( $antes', 'consulta_leve_htaccess_desfazer(', "'estado'    => 'ativo'"];
let anterior = -1;
for (const passo of passos) {
  const aqui = executar.indexOf(passo);
  assert.ok(aqui > anterior, `ordem da escrita: ${passo}`);
  anterior = aqui;
}
assert.equal((ht.match(/'estado'\s+=> 'ativo'/g) || []).length, 1, 'ativo só depois da conferência');
assert.match(corpo('consulta_leve_htaccess_guardar_copia'), /CONSULTA_LEVE_OPCAO_HTACCESS_COPIA,[\s\S]*\n\t\t\tfalse\n/, 'cópia sem autoload');
assert.match(corpo('consulta_leve_htaccess_escrever'), /consulta_leve_htaccess_ler\( \$arquivo \) !== \$esperado[\s\S]*rename\([\s\S]*flock\(/, 'confere antes, rename e trava');
tem(corpo('consulta_leve_htaccess_aplicar'), 'register_shutdown_function(');
tem(corpo('consulta_leve_htaccess_aplicar'), "consulta_leve_travar( 'htaccess'");
tem(corpo('consulta_leve_htaccess_pedir'), "'redirection' => 0");
tem(corpo('consulta_leve_htaccess_modo'), 'hash_equals( $token');
for (const gancho of ["'admin_init'", "'wp_loaded'", 'register_deactivation_hook(']) tem(ht, gancho);

// Configuração e painel.
const config = ler('consulta-leve/nucleo/config.php');
assert.match(config, /'cab_htaccess'\s+=> false,/, 'desligado por padrão');
assert.match(config, /chaves_proprias\(\): array \{[^}]*'cab_htaccess'/, 'fora do formulário geral');
const admin = ler('consulta-leve/admin/admin.php');
tem(admin, "function consulta_leve_admin_htaccess(): void {\n\t\tconsulta_leve_admin_conferir( 'htaccess' );", 'nonce e permissão antes de tudo');
tem(admin, "add_action( 'admin_post_consulta_leve_htaccess', 'consulta_leve_admin_htaccess' );");
tem(admin, "consulta_leve_admin_botao( 'htaccess'", 'nonce próprio');
tem(ler('consulta-leve/diagnostico/diagnostico.php'), "$d['htaccess'] ", 'estado no diagnóstico');
tem(ler('consulta-leve/consulta-leve.php'), "'seguranca/htaccess.php',");

const contrato = ler('docs/CONTRATO.md');
tem(contrato, '### 15.2 Cabeçalhos no `.htaccess` (`seguranca/htaccess.php`)');
tem(contrato, '| `cab_htaccess` | bool | false |');
console.log('htaccess: ok');
