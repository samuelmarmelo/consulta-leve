// Cabeçalhos no .htaccess em Apache 2.4 + WordPress de verdade (docs/CONTRATO.md, 15.2).
// Uso: node tests/apache/executar.mjs mod_php|fpm   (precisa de Docker e openssl; roda no GitHub Actions)
//
// Os cenários conferem o resultado (bytes do arquivo, respostas do site, estado
// e motivo gravados), não os passos internos do plugin. Todos os contêineres
// usam a rede do hospedeiro: o endereço do site vale igual para o PHP, para o
// WP-CLI e para este script.
import { spawnSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const variante = process.argv[2];
if (!['mod_php', 'fpm'].includes(variante)) {
  console.error('uso: node tests/apache/executar.mjs mod_php|fpm');
  process.exit(2);
}

// Imagens oficiais fixadas por digest.
const IMAGENS = {
  mod_php: 'wordpress:7.1-php8.3-apache@sha256:9b46af6a41fd0dcb2e5cf85033a6350d05010356847ede1cfcac5cf94292eb26',
  fpm: 'wordpress:php8.3-fpm@sha256:6b68dc97a2c0933071f4588c30ef1479b5ac595ac8da7cb7931be0bbdd2d0201',
  httpd: 'httpd:2.4.66@sha256:89a0b59e6d7285f00fc8df952a1579c31eb035cbdccb96690051e8bb6432cbea',
  cli: 'wordpress:cli-2.12-php8.3@sha256:fc50189ac8d3256891d0d964f8d80cf62189eb6262f53c7fd8bfc40debd71029',
  db: 'mariadb:11.4@sha256:a8992b5e5404d11e03c54a31d0c1bdea3fd116d283aa6d1a627551f29045309b',
};

const aqui = path.dirname(fileURLToPath(import.meta.url));
const raiz = path.resolve(aqui, '..', '..');
const conf = fs.mkdtempSync(path.join(os.tmpdir(), 'cl-apache-'));
fs.chmodSync(conf, 0o755);
const WP = '/var/www/html';
const ALVO = `${WP}/.htaccess`;
const HTTP = 'http://127.0.0.1:8080';
const HTTPS = 'https://127.0.0.1:8443';
const apache = variante === 'fpm' ? 'cl-httpd' : 'cl-php';
// Só desta execução, só dentro destes contêineres descartáveis.
const senha = { db: crypto.randomBytes(12).toString('hex'), wp: crypto.randomBytes(12).toString('hex') };
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

/* Docker ---------------------------------------------------------------------- */

function docker(args, { entrada, tolerar = false, bytes = false } = {}) {
  const r = spawnSync('docker', args, { input: entrada, maxBuffer: 64 * 1024 * 1024, timeout: 300000 });
  if (r.status !== 0 && !tolerar) {
    throw new Error(`docker ${args.slice(0, 4).join(' ')}: ${String(r.stderr || r.error || '').trim().slice(-600)}`);
  }
  if (bytes) return r.status === 0 ? r.stdout : null;
  return r.status === 0 ? String(r.stdout).trim() : '';
}
const sh = (comando, opcoes) => docker(['exec', ...(opcoes?.entrada !== undefined ? ['-i'] : []), 'cl-php', 'sh', '-c', comando], opcoes);
const wp = (...args) => docker(['exec', 'cl-cli', 'wp', '--skip-plugins', ...args]);
const wpComPlugin = (...args) => docker(['exec', 'cl-cli', 'wp', ...args], { tolerar: true });
const sql = (texto) => docker(['exec', '-i', 'cl-db', 'mariadb', '--port=3307', '--protocol=tcp', '-h127.0.0.1', '-uwp', `-p${senha.db}`, '-N', 'wp'], { entrada: texto });

async function ate(nome, segundos, fn) {
  const fim = Date.now() + segundos * 1000;
  for (;;) {
    let valor;
    try {
      valor = await fn();
    } catch {
      valor = false;
    }
    if (valor) return valor;
    if (Date.now() > fim) throw new Error(`tempo esgotado: ${nome}`);
    await dormir(500);
  }
}

/* Apache ---------------------------------------------------------------------- */

let geracao = 0;

function httpdConf({ override, headers }) {
  const pasta = variante === 'fpm' ? '/usr/local/apache2/modules' : '/usr/lib/apache2/modules';
  const modulos = ['unixd', 'authz_core', 'dir', 'mime', 'env', 'setenvif', 'alias', 'rewrite', 'ssl'];
  if (headers) modulos.push('headers');
  if (variante === 'fpm') modulos.push('proxy', 'proxy_fcgi');
  const mpm = variante === 'fpm' ? 'event' : 'prefork';
  return [
    `LoadModule mpm_${mpm}_module ${pasta}/mod_mpm_${mpm}.so`,
    ...modulos.map((m) => `<IfModule !${m}_module>\nLoadModule ${m}_module ${pasta}/mod_${m}.so\n</IfModule>`),
    ...(variante === 'fpm' ? [] : [`LoadModule php_module ${pasta}/libphp.so`]),
    'ServerRoot "/tmp"',
    'ServerName 127.0.0.1',
    'Listen 8080',
    'Listen 8443 https',
    'PidFile /tmp/cl-httpd.pid',
    'DefaultRuntimeDir /tmp',
    'ErrorLog /proc/self/fd/2',
    'LogLevel warn',
    'User www-data',
    'Group www-data',
    'TypesConfig /dev/null',
    'AddType text/html .html',
    'AddType image/gif .gif',
    'DirectoryIndex index.php index.html',
    `DocumentRoot ${WP}`,
    // Prova que esta configuração já está valendo; responde antes de qualquer .htaccess.
    `Redirect 410 /__cl_geracao_${geracao}`,
    '<Directory />\nAllowOverride None\nRequire all denied\n</Directory>',
    `<Directory ${WP}>\nOptions FollowSymLinks\nAllowOverride ${override}\nRequire all granted\n</Directory>`,
    '<Files ".ht*">\nRequire all denied\n</Files>',
    `<FilesMatch "\\.php$">\nSetHandler ${variante === 'fpm' ? '"proxy:fcgi://127.0.0.1:9000"' : 'application/x-httpd-php'}\n</FilesMatch>`,
    'SSLSessionCache none',
    '<VirtualHost *:8443>\nSSLEngine on\nSSLCertificateFile /cl-conf/teste.crt\nSSLCertificateKeyFile /cl-conf/teste.key\n</VirtualHost>',
    '',
  ].join('\n');
}

let confAtual = '';

async function configurar({ override = 'All', headers = true } = {}) {
  const chave = JSON.stringify({ override, headers });
  if (chave === confAtual) return;
  geracao++;
  fs.writeFileSync(path.join(conf, 'httpd.conf'), httpdConf({ override, headers }));
  if (confAtual) {
    const binario = variante === 'fpm' ? 'httpd' : 'apache2';
    docker(['exec', apache, binario, '-f', '/cl-conf/httpd.conf', '-t']);
    docker(['kill', '--signal', 'HUP', apache]);
    await ate('Apache com a configuração nova', 30, async () => (await pedir(`${HTTP}/__cl_geracao_${geracao}`)).status === 410);
  }
  confAtual = chave;
}

/* HTTP ------------------------------------------------------------------------ */

function pedir(url, { metodo = 'GET', corpo = null, cookies = null } = {}) {
  return new Promise((resolve, reject) => {
    const modulo = url.startsWith('https') ? https : http;
    const headers = { 'User-Agent': 'consulta-leve-teste' };
    if (cookies?.size) headers.Cookie = [...cookies].map(([k, v]) => `${k}=${v}`).join('; ');
    if (corpo !== null) {
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
      headers['Content-Length'] = Buffer.byteLength(corpo);
    }
    // Certificado próprio do teste, só em 127.0.0.1.
    const req = modulo.request(url, { method: metodo, headers, agent: false, rejectUnauthorized: false, timeout: 90000 }, (res) => {
      const partes = [];
      res.on('data', (p) => partes.push(p));
      res.on('error', reject);
      res.on('end', () => {
        const cab = {};
        for (let i = 0; i < res.rawHeaders.length; i += 2) (cab[res.rawHeaders[i].toLowerCase()] ??= []).push(res.rawHeaders[i + 1]);
        if (cookies) {
          for (const linha of cab['set-cookie'] || []) {
            const [par] = linha.split(';');
            const corte = par.indexOf('=');
            cookies.set(par.slice(0, corte), par.slice(corte + 1));
          }
        }
        resolve({ status: res.statusCode, cab, corpo: Buffer.concat(partes).toString('utf8') });
      });
    });
    req.on('timeout', () => req.destroy(new Error('tempo esgotado')));
    req.on('error', reject);
    req.end(corpo);
  });
}

let base = HTTP;
let sessao = new Map();

async function entrar() {
  sessao = new Map([['wordpress_test_cookie', 'WP%20Cookie%20check']]);
  const r = await pedir(`${base}/wp-login.php`, {
    metodo: 'POST',
    cookies: sessao,
    corpo: new URLSearchParams({ log: 'admin', pwd: senha.wp, testcookie: '1', redirect_to: `${base}/wp-admin/` }).toString(),
  });
  if (r.status !== 302 || ![...sessao.keys()].some((k) => k.startsWith('wordpress_logged_in_'))) throw new Error(`login falhou (${r.status})`);
}

const desfazerEntidades = (t) => t.replace(/&#0?38;|&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'");
const atributo = (tag, nome) => {
  const m = tag.match(new RegExp(`\\s${nome}=(?:"([^"]*)"|'([^']*)')`));
  return m ? desfazerEntidades(m[1] ?? m[2]) : null;
};

/** Campos escondidos do formulário da ação na aba Cabeçalhos (nonce de verdade). */
async function formulario(acao) {
  const r = await pedir(`${base}/wp-admin/tools.php?page=consulta-leve&aba=cabecalhos`, { cookies: sessao });
  if (r.status !== 200) throw new Error(`aba Cabeçalhos respondeu ${r.status}`);
  for (const m of r.corpo.matchAll(/<form\b[^>]*>[\s\S]*?<\/form>/g)) {
    const campos = {};
    for (const tag of m[0].match(/<input\b[^>]*>/g) || []) {
      if (atributo(tag, 'type') === 'hidden' && atributo(tag, 'name')) campos[atributo(tag, 'name')] = atributo(tag, 'value') ?? '';
    }
    if (campos.action === `consulta_leve_${acao}`) return { destino: atributo(m[0].match(/<form\b[^>]*>/)[0], 'action'), campos };
  }
  return null;
}

async function enviar(acao, extra = {}) {
  const f = await formulario(acao);
  if (!f) return { status: 0, aviso: 'sem_formulario' };
  if (!f.campos._wpnonce) throw new Error('formulário sem nonce');
  const r = await pedir(f.destino, { metodo: 'POST', cookies: sessao, corpo: new URLSearchParams({ ...f.campos, ...extra }).toString() });
  const aviso = (r.cab.location?.[0] || '').match(/cl_aviso=([a-z_]+)/)?.[1] || '';
  return { status: r.status, aviso };
}

const TODOS = { cab_nosniff: '1', cab_referrer: '1', cab_frame: '1', cab_upgrade: '1', cab_hsts: 'ano' };
const salvar = (escolhas = TODOS) => enviar('cabecalhos', Object.fromEntries(Object.entries(escolhas).map(([k, v]) => [`cl[${k}]`, v])));
const ligar = () => enviar('htaccess', { operacao: 'ligar' });
const desligar = () => enviar('htaccess', { operacao: 'desligar' });

/* Arquivo e estado -------------------------------------------------------------- */

const ler = () => docker(['exec', 'cl-php', 'cat', ALVO], { bytes: true, tolerar: true });
const sha = (b) => (b === null ? '(sem arquivo)' : crypto.createHash('sha256').update(b).digest('hex'));
const igual = (a, b) => (a === null || b === null ? a === b : a.equals(b));
const dados = () => sh(`stat -c '%a %u:%g' ${ALVO}`);
const listarHt = () => sh(`find ${WP} -name '.ht*' | sort`);

function escrever(conteudo, { dono = 'www-data:www-data', modo = '644' } = {}) {
  if (conteudo === null) sh(`rm -f ${ALVO}`);
  else sh(`rm -f ${ALVO} && cat > ${ALVO} && chown ${dono} ${ALVO} && chmod ${modo} ${ALVO}`, { entrada: conteudo });
}

function estado() {
  const texto = docker(['exec', 'cl-cli', 'wp', '--skip-plugins', 'option', 'get', 'consulta_leve_htaccess', '--format=json'], { tolerar: true });
  return texto ? JSON.parse(texto) : { estado: '', motivo: '' };
}

const mu = (nome) => {
  sh(`mkdir -p ${WP}/wp-content/mu-plugins`);
  docker(['cp', path.join(aqui, 'mu', `${nome}.php`), `cl-php:${WP}/wp-content/mu-plugins/${nome}.php`]);
  sh(`chmod 644 ${WP}/wp-content/mu-plugins/${nome}.php`);
};
const muFora = (nome) => sh(`rm -f ${WP}/wp-content/mu-plugins/${nome}.php`);

const BLOCO_WP = [
  '# BEGIN WordPress',
  '<IfModule mod_rewrite.c>',
  'RewriteEngine On',
  'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
  'RewriteBase /',
  'RewriteRule ^index\\.php$ - [L]',
  'RewriteCond %{REQUEST_FILENAME} !-f',
  'RewriteCond %{REQUEST_FILENAME} !-d',
  'RewriteRule . /index.php [L]',
  '</IfModule>',
  '# END WordPress',
  '',
].join('\n');
const ORIGINAL = Buffer.from(`# Regras do dono do site\nOptions -Indexes\n\n${BLOCO_WP}`);

/* Cenários ---------------------------------------------------------------------- */

const VALOR = {
  'X-Content-Type-Options': 'nosniff',
  'Referrer-Policy': 'strict-origin-when-cross-origin',
  'X-Frame-Options': 'SAMEORIGIN',
  'Content-Security-Policy': 'upgrade-insecure-requests',
  'Strict-Transport-Security': 'max-age=31536000',
};
const NOMES = Object.keys(VALOR);
const SO_HTTP = NOMES.slice(0, 3);
const relatorio = [];
let atual = null;

function ok(condicao, texto) {
  atual.itens.push(`${condicao ? 'ok   ' : 'FALHA'} ${texto}`);
  if (!condicao) atual.falhas.push(texto);
  console.log(`  ${condicao ? 'ok   ' : 'FALHA'} ${texto}`);
  return condicao;
}

/** Uma vez cada, com o valor esperado; os demais nomes ausentes. */
function cabecalhos(rotulo, r, esperados, status = 200) {
  ok(r.status === status, `${rotulo}: status ${r.status} (esperado ${status})`);
  const achado = (nome) => r.cab[nome.toLowerCase()] || [];
  for (const nome of NOMES) {
    const quer = esperados[nome];
    const tem = achado(nome);
    const texto = `${rotulo}: ${nome} ${JSON.stringify(tem)}`;
    if (quer === undefined) ok(tem.length === 0, `${texto} (esperado nenhum)`);
    else ok(tem.length === 1 && (quer === null || tem[0] === quer), `${texto} (esperado uma vez ${quer ?? 'qualquer valor'})`);
  }
}

const so = (nomes, trocas = {}) => ({ ...Object.fromEntries(nomes.map((n) => [n, VALOR[n]])), ...trocas });
const home = () => pedir(`${base}/`);
const gif = () => pedir(`${base}/wp-includes/images/blank.gif`);

async function siteNoAr(rotulo) {
  const [h, g] = [await home(), await gif()];
  ok(h.status === 200 && g.status === 200, `${rotulo}: página inicial ${h.status}, estático ${g.status}`);
}

function bytes(rotulo, antes) {
  const depois = ler();
  ok(igual(antes, depois), `${rotulo}: sha256 antes ${sha(antes).slice(0, 16)} depois ${sha(depois).slice(0, 16)}, byte a byte igual`);
}

function recusado(motivos) {
  const e = estado();
  ok(e.estado === 'recusado' && motivos.test(e.motivo), `estado ${e.estado || '(vazio)'}, motivo ${e.motivo || '(vazio)'} (esperado recusado ${motivos})`);
}

const ativo = () => {
  const e = estado();
  return ok(e.estado === 'ativo', `estado ${e.estado || '(vazio)'}${e.motivo ? `, motivo ${e.motivo}` : ''} (esperado ativo)`);
};

async function cenario(id, nome, fn, { conteudo = ORIGINAL, apacheConf = {}, arquivo = {}, escolhas = TODOS } = {}) {
  atual = { id, nome, falhas: [], itens: [] };
  const inicio = Date.now();
  console.log(`::group::[${variante}] ${id}. ${nome}`);
  try {
    sh(`rm -f ${WP}/wp-content/mu-plugins/*.php /tmp/cl-morto`);
    escrever(ORIGINAL);
    await configurar();
    sql("DELETE FROM wp_options WHERE option_name LIKE 'consulta\\_leve\\_htaccess%' OR option_name LIKE 'consulta\\_leve\\_trava\\_%' OR option_name = 'consulta_leve_config';");
    ok((await salvar(escolhas)).status === 302, 'escolhas salvas pelo formulário');
    await configurar(apacheConf);
    escrever(conteudo, arquivo);
    const antes = ler();
    console.log(`  sha256 inicial ${sha(antes)}`);
    const ht = listarHt();
    await fn(antes);
    const final = ler();
    console.log(`  sha256 final   ${sha(final)}`);
    atual.shas = [sha(antes), sha(final)];
    const depois = listarHt().split('\n').filter((l) => l !== ALVO).join('\n');
    ok(depois === ht.split('\n').filter((l) => l !== ALVO).join('\n'), 'nenhum outro arquivo .ht* ficou para trás');
  } catch (erro) {
    ok(false, `exceção: ${erro.message}`);
  }
  console.log('::endgroup::');
  if (atual.falhas.length) {
    console.log(`::group::[${variante}] ${id}: logs dos contêineres`);
    for (const c of new Set(['cl-php', apache])) console.log(String(spawnSync('docker', ['logs', '--tail', '25', c]).stderr));
    console.log('::endgroup::');
  }
  console.log(`${atual.falhas.length ? 'FALHOU' : 'PASSOU'} [${variante}] ${id}. ${nome} (${Math.round((Date.now() - inicio) / 1000)} s)`);
  relatorio.push(atual);
}

async function todos() {
  // Controle negativo, sem o plugin escrever: as armadilhas quebram mesmo e o
  // teste enxerga repetição. Confere também as duas tabelas do mod_headers.
  await cenario('0', 'as armadilhas dos cenários funcionam', async (antes) => {
    const linha = (tabela) => Buffer.concat([Buffer.from(`Header ${tabela} set X-Frame-Options "SAMEORIGIN"\n`), antes]);
    const vezes = async (tabela) => {
      escrever(linha(tabela));
      return ((await home()).cab['x-frame-options'] || []).length;
    };
    const [sempre, sucesso] = [await vezes('always'), await vezes('onsuccess')];
    ok(variante === 'fpm' ? sempre === 1 && sucesso === 2 : sempre === 2 && sucesso === 1, `cabeçalho do PHP mais o do servidor: always ${sempre} vez(es), onsuccess ${sucesso} vez(es)`);

    const bloco = Buffer.from('# BEGIN Consulta Leve\n<IfModule mod_headers.c>\nHeader set X-Content-Type-Options "nosniff"\n</IfModule>\n# END Consulta Leve\n');
    const comBloco = Buffer.concat([bloco, antes]);
    escrever(comBloco);
    const visto = await gif();
    ok(visto.status === 200 && visto.cab['x-content-type-options']?.length === 1, 'com tudo ligado, o estático recebe o cabeçalho');
    for (const [rotulo, opcoes, status] of [['sem mod_headers', { headers: false }, 200], ['AllowOverride None', { override: 'None' }, 200], ['AllowOverride sem FileInfo', { override: 'Indexes' }, 500]]) {
      await configurar(opcoes);
      escrever(status === 500 ? bloco : comBloco);
      const r = await gif();
      ok(r.status === status && !(r.status === 200 && r.cab['x-content-type-options']), `${rotulo}: estático ${r.status}, ${r.cab['x-content-type-options'] ? 'com' : 'sem'} cabeçalho`);
    }
    await configurar();
    mu('erro-com-bloco');
    escrever(comBloco);
    const quebrada = await home();
    escrever(antes);
    ok(quebrada.status === 500 && (await home()).status === 200, `página inicial ${quebrada.status} com o bloco e 200 sem ele`);
  });

  await cenario('1', 'caminho feliz pelo formulário', async () => {
    const r = await ligar();
    ok(r.status === 302 && r.aviso === 'htaccess_ligado', `admin-post: ${r.status} ${r.aviso}`);
    if (!ativo()) return;
    const texto = ler().toString('utf8');
    ok(texto.includes('# END Consulta Leve\n# BEGIN WordPress\n'), 'bloco imediatamente antes de # BEGIN WordPress');
    ok(texto.split('# BEGIN Consulta Leve').length === 2, 'um bloco só');
    cabecalhos('página inicial', await home(), so(SO_HTTP));
    cabecalhos('estático', await gif(), so(SO_HTTP));
    const incorporacao = await pedir(`${base}/hello-world/embed/`);
    ok(/wp-embed/.test(incorporacao.corpo), 'a página de incorporação veio mesmo');
    cabecalhos('incorporação', incorporacao, so(SO_HTTP.slice(0, 2)));
    cabecalhos('artigo', await pedir(`${base}/hello-world/`), so(SO_HTTP));
    cabecalhos('wp-login.php', await pedir(`${base}/wp-login.php`), so(SO_HTTP, { 'Content-Security-Policy': (await pedir(`${base}/wp-login.php`)).cab['content-security-policy'] ? null : undefined }));
    const painel = await pedir(`${base}/wp-admin/`, { cookies: sessao });
    ok(painel.status === 200, `painel ${painel.status}`);
  });

  await cenario('2', 'repetir, mudar opções e desligar', async (antes) => {
    ok((await ligar()).aviso === 'htaccess_ligado', 'ligado');
    if (!ativo()) return;
    const primeiro = ler();
    const r = await ligar();
    ok(r.aviso === 'htaccess_ligado' && igual(primeiro, ler()), `ligar de novo (${r.aviso}) deixa o arquivo igual`);
    ativo();
    const s = await salvar({ cab_nosniff: '1', cab_referrer: '1', cab_hsts: 'nao' });
    ok(s.aviso === 'salvo', `salvar outras escolhas: ${s.aviso}`);
    ativo();
    const texto = ler().toString('utf8');
    ok(!igual(primeiro, ler()) && !texto.includes('X-Frame-Options') && texto.includes('Referrer-Policy'), 'bloco reescrito com as escolhas novas');
    cabecalhos('estático', await gif(), so(SO_HTTP.slice(0, 2)));
    cabecalhos('página inicial', await home(), so(SO_HTTP.slice(0, 2)));
    const d = await desligar();
    ok(d.aviso === 'htaccess_desligado' && estado().estado === 'desligado', `desligar: ${d.aviso}, estado ${estado().estado}`);
    bytes('depois de desligar', antes);
    cabecalhos('página inicial (PHP)', await home(), so(SO_HTTP.slice(0, 2)));
    cabecalhos('estático', await gif(), {});
  });

  await cenario('3', 'mod_headers desligado', async (antes) => {
    const r = await ligar();
    ok(r.aviso === 'htaccess_recusado', `admin-post: ${r.aviso}`);
    recusado(/^ausente$|canari/);
    bytes('recusado', antes);
    cabecalhos('página inicial (PHP)', await home(), so(SO_HTTP));
    await siteNoAr('depois');
  }, { apacheConf: { headers: false } });

  await cenario('4', 'AllowOverride None', async (antes) => {
    const r = await ligar();
    ok(r.aviso === 'htaccess_recusado', `admin-post: ${r.aviso}`);
    recusado(/^ausente$|canari/);
    bytes('recusado', antes);
    cabecalhos('página inicial (PHP)', await home(), so(SO_HTTP));
    await siteNoAr('depois');
  }, { apacheConf: { override: 'None' } });

  for (const ordem of ['antes', 'depois']) {
    await cenario(ordem === 'antes' ? '5a' : '5b', `X-Frame-Options de terceiros pelo PHP, ${ordem} do bloco`, async (antes) => {
      if (ordem === 'antes') mu('quadro-do-tema');
      ok((await ligar()).aviso === 'htaccess_ligado', 'ligado');
      ativo();
      if (ordem === 'depois') mu('quadro-do-tema');
      cabecalhos('página inicial', await home(), so(SO_HTTP, { 'X-Frame-Options': 'DENY' }));
      cabecalhos('artigo', await pedir(`${base}/hello-world/`), so(SO_HTTP, { 'X-Frame-Options': 'DENY' }));
      cabecalhos('estático', await gif(), so(SO_HTTP));
      await desligar();
      bytes('depois de desligar', antes);
    });
  }

  await cenario('6a', 'servidor não aceita Header no .htaccess (500 de verdade)', async (antes) => {
    const r = await ligar();
    ok(r.aviso === 'htaccess_recusado', `admin-post: ${r.aviso}`);
    recusado(/^erro_5xx$|canari/);
    bytes('restaurado', antes);
    await siteNoAr('depois');
    cabecalhos('página inicial (PHP)', await home(), so(SO_HTTP));
  }, { conteudo: Buffer.from('# BEGIN WordPress\n# END WordPress\n'), apacheConf: { override: 'Indexes' } });

  await cenario('6b', 'página inicial quebra quando o bloco aparece', async (antes) => {
    mu('erro-com-bloco');
    const r = await ligar();
    ok(r.aviso === 'htaccess_recusado', `admin-post: ${r.aviso}`);
    recusado(/^erro_5xx$/);
    bytes('restaurado', antes);
    await siteNoAr('depois');
    cabecalhos('página inicial (PHP)', await home(), so(SO_HTTP));
  });

  await cenario('6c', 'outro processo muda o arquivo no meio', async (antes) => {
    mu('outro-escreve');
    const r = await ligar();
    muFora('outro-escreve');
    ok(r.aviso === 'htaccess_recusado', `admin-post: ${r.aviso}`);
    recusado(/^mudou$/);
    ok(igual(ler(), Buffer.concat([antes, Buffer.from('# linha de outro processo\n')])), 'saiu só o nosso bloco; a linha do outro processo ficou');
    await siteNoAr('depois');
  });

  await cenario('7', 'PHP morto no meio, depois abre o painel', async (antes) => {
    mu('matar');
    const r = await ligar().catch((e) => ({ status: 0, aviso: e.message }));
    ok(r.status !== 302, `o pedido morreu (${r.status} ${r.aviso})`);
    ok(sh('cat /tmp/cl-morto', { tolerar: true }) !== '', 'processo do PHP morto com SIGKILL');
    muFora('matar');
    ok(estado().estado === 'pendente' && ler().includes('# BEGIN Consulta Leve'), `logo depois: estado ${estado().estado}, bloco no arquivo`);
    await siteNoAr('com o bloco pendente');
    cabecalhos('página inicial pendente', await home(), so(SO_HTTP));
    const inicio = Date.now();
    await ate('painel desfazer a escrita', 240, async () => {
      await pedir(`${base}/wp-admin/index.php`, { cookies: sessao });
      if (estado().estado !== 'pendente') return true;
      await dormir(5000);
      return false;
    });
    ok(true, `painel desfez depois de ${Math.round((Date.now() - inicio) / 1000)} s`);
    recusado(/^interrompido$/);
    bytes('recuperado', antes);
    await siteNoAr('depois');
  });

  await cenario('7b', 'PHP morto com o site em 500, depois WP-CLI', async (antes) => {
    mu('matar');
    const r = await ligar().catch((e) => ({ status: 0, aviso: e.message }));
    muFora('matar');
    if (sh('cat /tmp/cl-morto', { tolerar: true }) === '') {
      ok(r.aviso === 'htaccess_recusado', `recusado antes de escrever no arquivo do site: ${r.aviso}`);
    } else {
      ok(estado().estado === 'pendente', `logo depois: estado ${estado().estado}`);
      const h = await home();
      ok(h.status === 500, `site em ${h.status} (limite declarado no contrato)`);
      const inicio = Date.now();
      await ate('WP-CLI desfazer a escrita', 240, async () => {
        wpComPlugin('eval', 'echo 1;');
        if (estado().estado !== 'pendente') return true;
        await dormir(5000);
        return false;
      });
      ok(true, `WP-CLI desfez depois de ${Math.round((Date.now() - inicio) / 1000)} s`);
      recusado(/^interrompido$/);
    }
    bytes('recuperado', antes);
    await siteNoAr('depois');
  }, { conteudo: Buffer.from('# BEGIN WordPress\n# END WordPress\n'), apacheConf: { override: 'Indexes' } });

  await cenario('8', 'desativar o plugin tira só o nosso bloco', async (antes) => {
    ok((await ligar()).aviso === 'htaccess_ligado', 'ligado');
    ativo();
    const outro = Buffer.from('\n# BEGIN Outro Plugin\nAddType text/plain .txt2\n# END Outro Plugin\n');
    sh(`cat >> ${ALVO}`, { entrada: outro });
    const lista = await pedir(`${base}/wp-admin/plugins.php`, { cookies: sessao });
    const link = (lista.corpo.match(/<a\b[^>]*href="[^"]*action=deactivate[^"]*consulta-leve[^"]*"/g) || []).map((t) => atributo(t, 'href'))[0];
    ok(Boolean(link), 'link Desativar com nonce na lista de plugins');
    const r = await pedir(new URL(link, `${base}/wp-admin/`).href, { cookies: sessao });
    ok(r.status === 302, `desativação: ${r.status}`);
    ok(!wp('option', 'get', 'active_plugins', '--format=json').includes('consulta-leve'), 'plugin inativo');
    ok(igual(ler(), Buffer.concat([antes, outro])), 'arquivo é o original mais o bloco do outro plugin, byte a byte');
    await siteNoAr('depois');
    wpComPlugin('plugin', 'activate', 'consulta-leve');
  });
  wpComPlugin('plugin', 'activate', 'consulta-leve');

  const CRLF = Buffer.from(
    [
      '# BEGIN Cache Fictício',
      '<IfModule mod_expires.c>',
      'ExpiresActive On',
      '</IfModule>',
      '# END Cache Fictício',
      '',
      '  # comentário com espaços no fim   ',
      ...BLOCO_WP.trimEnd().split('\n'),
      '',
      '# BEGIN Segurança Fictícia',
      '<Files "segredo.txt">',
      'Require all denied',
      '</Files>',
      '# END Segurança Fictícia',
    ].join('\r\n'),
  );
  await cenario('9', 'arquivo com CRLF e blocos de outros plugins', async (antes) => {
    const modo = dados();
    ok((await ligar()).aviso === 'htaccess_ligado', 'ligado');
    if (!ativo()) return;
    const texto = ler().toString('utf8');
    ok(texto.includes('# END Consulta Leve\r\n# BEGIN WordPress\r\n'), 'bloco antes do WordPress, com CRLF');
    ok(!/[^\r]\n/.test(texto), 'nenhuma quebra de linha LF solta');
    const corte = texto.replace(/# BEGIN Consulta Leve[\s\S]*# END Consulta Leve\r\n/, '');
    ok(Buffer.from(corte).equals(antes), 'fora do bloco, byte a byte igual');
    ok(dados() === modo, `modo e dono ${dados()} (antes ${modo})`);
    cabecalhos('página inicial', await home(), so(SO_HTTP));
    cabecalhos('estático', await gif(), so(SO_HTTP));
    await desligar();
    bytes('depois de desligar', antes);
    ok(dados() === modo, `modo e dono ${dados()} (antes ${modo})`);
  }, { conteudo: CRLF, arquivo: { modo: '640' } });

  await cenario('9b', 'sem .htaccess antes', async (antes) => {
    ok((await ligar()).aviso === 'htaccess_ligado', 'ligado');
    ativo();
    cabecalhos('estático', await gif(), so(SO_HTTP));
    cabecalhos('página inicial', await home(), so(SO_HTTP));
    await desligar();
    bytes('depois de desligar', antes);
  }, { conteudo: null });

  await cenario('9c', 'arquivo de outro dono', async (antes) => {
    const modo = dados();
    const r = await ligar();
    if (r.aviso === 'htaccess_ligado') {
      ativo();
      cabecalhos('estático', await gif(), so(SO_HTTP));
      ok(dados() === modo, `modo e dono ${dados()} (antes ${modo})`);
      await desligar();
    } else {
      recusado(/^sem_escrita$|dono|atomic/);
    }
    ok(dados() === modo, `modo e dono ${dados()} (antes ${modo})`);
    bytes('no fim', antes);
    await siteNoAr('depois');
  }, { arquivo: { dono: 'root:root', modo: '666' } });

  // Com o bloco ativo o PHP cala: nenhuma resposta do WordPress pode perder cabeçalho.
  await cenario('10', '404, redirecionamento, 403 e REST do PHP com o bloco ativo', async () => {
    mu('respostas');
    const pedidos = {
      404: [`${base}/nao-existe-mesmo/`, 404],
      '301 canônico': [`${base}/?p=1`, 301],
      '302 do PHP': [`${base}/?cl_redir=1`, 302],
      '403 wp_die': [`${base}/?cl_morre=1`, 403],
      'REST /wp-json/': [`${base}/wp-json/`, 200],
      'REST 404': [`${base}/wp-json/nada/v1/nada`, 404],
      'feed': [`${base}/feed/`, 200],
    };
    for (const [rotulo, [url]] of Object.entries(pedidos)) {
      const r = await pedir(url);
      console.log(`  só PHP, ${rotulo}: ${r.status} ${SO_HTTP.map((n) => `${n}=${JSON.stringify(r.cab[n.toLowerCase()] || [])}`).join(' ')}`);
    }
    ok((await ligar()).aviso === 'htaccess_ligado', 'ligado');
    if (!ativo()) return;
    for (const [rotulo, [url, status]] of Object.entries(pedidos)) cabecalhos(rotulo, await pedir(url), so(SO_HTTP), status);
  });

  // HTTPS: as linhas condicionadas a %{HTTPS}.
  base = HTTPS;
  for (const o of ['siteurl', 'home']) wp('option', 'update', o, HTTPS);
  await entrar();
  await cenario('12', 'HTTPS com CSP e HSTS', async (antes) => {
    ok((await ligar()).aviso === 'htaccess_ligado', 'ligado');
    if (!ativo()) return;
    cabecalhos('página inicial', await home(), so(NOMES));
    cabecalhos('estático', await gif(), so(NOMES));
    cabecalhos('estático por HTTP', await pedir(`${HTTP}/wp-includes/images/blank.gif`), so(SO_HTTP));
    await desligar();
    bytes('depois de desligar', antes);
  });
}

/* Preparo ----------------------------------------------------------------------- */

const CONTEINERES = ['cl-cli', 'cl-httpd', 'cl-php', 'cl-db'];

async function preparar() {
  docker(['rm', '-f', ...CONTEINERES], { tolerar: true });
  docker(['volume', 'rm', '-f', 'cl-html'], { tolerar: true });
  const r = spawnSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2', '-subj', '/CN=127.0.0.1', '-keyout', path.join(conf, 'teste.key'), '-out', path.join(conf, 'teste.crt')]);
  if (r.status !== 0) throw new Error('openssl falhou');
  // Sem opcache: os mu-plugins de teste entram e saem entre pedidos.
  fs.writeFileSync(path.join(conf, 'zz-cl.ini'), 'opcache.enable=0\n');
  await configurar();

  docker(['run', '-d', '--name', 'cl-db', '--network', 'host', '-e', 'MARIADB_RANDOM_ROOT_PASSWORD=1', '-e', 'MARIADB_DATABASE=wp', '-e', 'MARIADB_USER=wp', '-e', `MARIADB_PASSWORD=${senha.db}`, IMAGENS.db, '--port=3307', '--bind-address=127.0.0.1']);
  const extra = "define('WP_HTTP_BLOCK_EXTERNAL', true); define('DISABLE_WP_CRON', true); define('AUTOMATIC_UPDATER_DISABLED', true);";
  const ambiente = ['-e', 'WORDPRESS_DB_HOST=127.0.0.1:3307', '-e', 'WORDPRESS_DB_USER=wp', '-e', `WORDPRESS_DB_PASSWORD=${senha.db}`, '-e', 'WORDPRESS_DB_NAME=wp', '-e', `WORDPRESS_CONFIG_EXTRA=${extra}`];
  const comum = ['--network', 'host', '-v', 'cl-html:/var/www/html', '-v', `${conf}:/cl-conf:ro`];
  const ini = ['-v', `${path.join(conf, 'zz-cl.ini')}:/usr/local/etc/php/conf.d/zz-cl.ini:ro`];
  const comando = variante === 'fpm' ? ['php-fpm'] : ['apache2', '-f', '/cl-conf/httpd.conf', '-D', 'FOREGROUND'];
  docker(['run', '-d', '--name', 'cl-php', ...comum, ...ini, ...ambiente, IMAGENS[variante], ...comando]);
  await ate('arquivos do WordPress', 90, () => sh(`test -s ${WP}/wp-config.php && test -s ${WP}/wp-includes/images/blank.gif && echo sim`, { tolerar: true }) === 'sim');
  if (variante === 'fpm') docker(['run', '-d', '--name', 'cl-httpd', ...comum, IMAGENS.httpd, 'httpd', '-f', '/cl-conf/httpd.conf', '-D', 'FOREGROUND']);
  docker(['run', '-d', '--name', 'cl-cli', '--user', '33:33', '--network', 'host', '-v', 'cl-html:/var/www/html', '-e', 'HOME=/tmp', ...ambiente, '--entrypoint', 'sleep', IMAGENS.cli, '7200']);
  await ate('MariaDB', 90, () => sql('SELECT 1;') === '1');
  await ate('Apache', 60, async () => (await pedir(`${HTTP}/__cl_geracao_${geracao}`)).status === 410);

  docker(['cp', path.join(raiz, 'consulta-leve'), `cl-php:${WP}/wp-content/plugins/`]);
  sh(`chown -R www-data:www-data ${WP}/wp-content/plugins/consulta-leve && chmod -R u=rwX,go=rX ${WP}/wp-content/plugins/consulta-leve`);
  wp('core', 'install', `--url=${HTTP}`, '--title=Teste', '--admin_user=admin', `--admin_password=${senha.wp}`, '--admin_email=teste@example.com', '--skip-email');
  wp('option', 'update', 'permalink_structure', '/%postname%/');
  wp('rewrite', 'flush');
  docker(['exec', 'cl-cli', 'wp', 'plugin', 'activate', 'consulta-leve']);
  escrever(ORIGINAL);
  const r1 = await home();
  if (r1.status !== 200) throw new Error(`página inicial respondeu ${r1.status}`);
  console.log(`Apache: ${(r1.cab.server || []).join()} | PHP: ${sh('php -r "echo PHP_VERSION;"')} | WordPress: ${wp('core', 'version')} | SAPI: ${variante}`);
  await entrar();
}

let fatal = '';
try {
  await preparar();
  await todos();
} catch (erro) {
  fatal = erro.stack || String(erro);
  console.log(`FALHA no preparo: ${fatal}`);
  for (const c of CONTEINERES) {
    const l = spawnSync('docker', ['logs', '--tail', '30', c]);
    console.log(`--- ${c}\n${l.stdout}${l.stderr}`);
  }
} finally {
  docker(['rm', '-f', ...CONTEINERES], { tolerar: true });
  docker(['volume', 'rm', '-f', 'cl-html'], { tolerar: true });
  fs.rmSync(conf, { recursive: true, force: true });
}

// Notas legíveis sem abrir o log.
const falharam = relatorio.filter((c) => c.falhas.length);
const linha = (t) => t.replace(/%/g, '%25').replace(/\r/g, '%0D').replace(/\n/g, '%0A');
const resumo = relatorio.map((c) => `${c.falhas.length ? 'FALHOU' : 'passou'} ${c.id}. ${c.nome} (${c.itens.length} checagens${c.shas ? `, sha ${c.shas.map((s) => s.slice(0, 12)).join(' > ')}` : ''})`).join('\n');
console.log(`::notice title=${variante}: ${relatorio.length - falharam.length}/${relatorio.length} cenários::${linha(resumo)}`);
for (const c of falharam.slice(0, 9)) console.log(`::error title=${variante} ${c.id}::${linha(`${c.nome}\n${c.falhas.join('\n')}`.slice(0, 3500))}`);
if (fatal) console.log(`::error title=${variante} preparo::${linha(fatal.slice(0, 1500))}`);
if (process.env.GITHUB_STEP_SUMMARY) {
  fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, `## ${variante}\n\n\`\`\`\n${resumo}\n${falharam.map((c) => `\n${c.id}:\n${c.falhas.join('\n')}`).join('\n')}\n\`\`\`\n`);
}
process.exit(fatal || falharam.length ? 1 : 0);
