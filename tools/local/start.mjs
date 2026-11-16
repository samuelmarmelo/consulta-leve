#!/usr/bin/env node
/**
 * Sobe o WordPress local da Consulta Leve com o WordPress Playground CLI
 * (SQLite, sem Docker nem MySQL). Porte da loja C tools/local/start.mjs.
 *
 * Sessões usam tools/local/central.mjs, que chama este script no worktree
 * central. Este arquivo sozinho só serve para depurar a subida.
 *
 *   CONSULTA_LEVE_LOCAL_PORT  porta (padrão 9470, livre entre os projetos)
 *   CONSULTA_LEVE_CLI_DIR     pasta de um Playground CLI já instalado
 *   PLAYGROUND_CLI_VERSION    versão a instalar (padrão 3.1.57)
 *
 * Monta consulta-leve/ em wp-content/plugins/consulta-leve, ativa o plugin e
 * roda a semente (6 mil posts com metas). debug.log em tools/local/.logs/.
 * Credencial gerada em tools/local/admin.local.json (ignorado pelo git),
 * aplicada ao usuário admin pelo blueprint e nunca impressa.
 */
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { createServer } from 'node:net';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const PORTA = Number(process.env.CONSULTA_LEVE_LOCAL_PORT || 9470);
const CLI_VERSAO = process.env.PLAYGROUND_CLI_VERSION || '3.1.57';
const aqui = dirname(fileURLToPath(import.meta.url));
const raiz = resolve(aqui, '..', '..');
const cliDir = process.env.CONSULTA_LEVE_CLI_DIR ? resolve(process.env.CONSULTA_LEVE_CLI_DIR) : resolve(aqui, '.cli');
const cliBin = resolve(cliDir, 'node_modules', '@wp-playground', 'cli', 'wp-playground.js');
const credencial = resolve(aqui, 'admin.local.json');
const logs = resolve(aqui, '.logs');
const npmCli = resolve(dirname(process.execPath), 'node_modules', 'npm', 'bin', 'npm-cli.js');

function portaLivre(porta) {
  return new Promise((ok) => {
    const s = createServer();
    s.once('error', () => ok(false));
    s.once('listening', () => s.close(() => ok(true)));
    s.listen(porta, '127.0.0.1');
  });
}

if (!(await portaLivre(PORTA))) {
  console.log(`[consulta-leve-local] a porta ${PORTA} já está em uso. Não subi outro Playground; veja com: node tools/local/central.mjs status`);
  process.exit(0);
}

if (!existsSync(cliBin)) {
  mkdirSync(cliDir, { recursive: true });
  console.log(`[consulta-leve-local] instalando @wp-playground/cli@${CLI_VERSAO} em ${cliDir} ...`);
  const [cmd, pre] = existsSync(npmCli) ? [process.execPath, [npmCli]] : ['npm', []];
  const r = spawnSync(cmd, [...pre, 'install', '--prefix', cliDir, '--ignore-scripts', '--no-audit', '--no-fund', '--loglevel=error', `@wp-playground/cli@${CLI_VERSAO}`], { stdio: 'inherit' });
  if (r.status !== 0 || !existsSync(cliBin)) {
    console.error('[consulta-leve-local] falha ao instalar o Playground CLI.');
    process.exit(1);
  }
}

if (!existsSync(credencial)) {
  writeFileSync(credencial, `${JSON.stringify({ user: 'admin', password: randomBytes(18).toString('base64url'), email: 'admin@consulta-leve.test' }, null, 2)}\n`, { mode: 0o600 });
  console.log('[consulta-leve-local] credencial local gerada em tools/local/admin.local.json (não é impressa).');
}

mkdirSync(logs, { recursive: true });

// Caminhos relativos no --mount: o "C:" do Windows quebraria o separador ":".
const args = [
  cliBin,
  'server',
  `--port=${PORTA}`,
  '--php=8.3',
  '--wp=latest',
  '--mount=consulta-leve:/wordpress/wp-content/plugins/consulta-leve',
  '--mount=tools/local:/consulta-leve-local',
  '--blueprint=tools/local/blueprint.json',
  `--site-url=http://127.0.0.1:${PORTA}`,
];

console.log(`[consulta-leve-local] http://127.0.0.1:${PORTA}/  (wp-admin: /wp-admin/)`);
const filho = spawn(process.execPath, args, { cwd: raiz, stdio: 'inherit' });
const parar = () => filho.kill('SIGINT');
process.on('SIGINT', parar);
process.on('SIGTERM', parar);
filho.on('exit', (codigo) => process.exit(codigo ?? 0));
