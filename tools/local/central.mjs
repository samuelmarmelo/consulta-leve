#!/usr/bin/env node
/**
 * WordPress local central da Consulta Leve. Porte da loja C
 * tools/local/central.mjs, com as quatro melhorias anotadas no porte da
 * loja A: log na pasta comum do git, pronto conferido por uma página da
 * semente, PID conferido antes de encerrar e worktrees fora do git.
 *
 * Um só Playground para todas as sessões e worktrees: o PC tem 16 GB e cada
 * Playground passa de 1 GB. O servidor roda sozinho, fora de qualquer sessão,
 * e monta consulta-leve/ do worktree fixo `.claude/worktrees/playground`.
 * Trocar a branch em teste muda os arquivos no ar, sem reiniciar e sem perder
 * o banco. Quem tiver código novo commita e pede `usar` com a branch.
 *
 * Comandos (de qualquer checkout do repositório):
 *   node tools/local/central.mjs status
 *   node tools/local/central.mjs subir [--ref <ref>] [--quem <sessão>]
 *   node tools/local/central.mjs usar <ref> --quem <sessão> [--forcar]
 *   node tools/local/central.mjs parar
 *
 * Trocar a ref que outra sessão usou há menos de 15 minutos é recusado sem
 * --forcar. Estado em `.git/consulta-leve-playground.json`, comum a todos os
 * worktrees. A senha fica em tools/local/admin.local.json do worktree fixo e
 * nunca é impressa. Porta 9470 (CONSULTA_LEVE_LOCAL_PORT).
 */
import { spawn, spawnSync } from 'node:child_process';
import { appendFileSync, closeSync, existsSync, mkdirSync, openSync, readFileSync, readdirSync, rmdirSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const PORTA = Number(process.env.CONSULTA_LEVE_LOCAL_PORT || 9470);
const URL_SITE = `http://127.0.0.1:${PORTA}`;
const RESERVA_MS = 15 * 60 * 1000;
const TRAVA_MS = 15 * 60 * 1000;
const ESPERA_MS = 12 * 60 * 1000;

const aqui = dirname(fileURLToPath(import.meta.url));

/** Roda git e devolve a saída; falha vira exceção com a mensagem do git. */
function git(args, cwd = aqui) {
  const r = spawnSync('git', args, { cwd, encoding: 'utf8' });
  if (r.status !== 0) throw new Error(`git ${args[0]}: ${(r.stderr || r.stdout).trim()}`);
  return r.stdout.trim();
}

const comum = resolve(aqui, git(['rev-parse', '--git-common-dir']));
const raiz = dirname(comum);
const pasta = join(raiz, '.claude', 'worktrees', 'playground');
const arquivoEstado = join(comum, 'consulta-leve-playground.json');
const trava = join(comum, 'consulta-leve-playground.trava');
// Log na pasta comum do git: sobrevive à troca de ref do worktree fixo.
const log = join(comum, 'consulta-leve-playground.log');

function lerEstado() {
  try {
    return JSON.parse(readFileSync(arquivoEstado, 'utf8'));
  } catch {
    return {};
  }
}

function gravarEstado(dados) {
  writeFileSync(arquivoEstado, `${JSON.stringify({ ...lerEstado(), ...dados }, null, 2)}\n`);
}

function opcao(nome) {
  const i = process.argv.indexOf(`--${nome}`);
  return i > -1 ? process.argv[i + 1] || '' : '';
}

function vivo(pid) {
  if (!pid) return false;
  try {
    process.kill(pid, 0);
    return true;
  } catch {
    return false;
  }
}

/** O PID é mesmo um node (e não outro programa que herdou o número)? */
function eNode(pid) {
  if (!vivo(pid)) return false;
  if (process.platform !== 'win32') return true;
  const r = spawnSync('tasklist', ['/FI', `PID eq ${pid}`, '/FO', 'CSV', '/NH'], { encoding: 'utf8' });
  return /"node\.exe"/i.test(r.stdout || '');
}

/** Status HTTP de um caminho, ou 0 sem resposta. */
async function http(caminho) {
  try {
    const r = await fetch(URL_SITE + caminho, { redirect: 'manual', signal: AbortSignal.timeout(30000) });
    await r.arrayBuffer();
    return r.status;
  } catch {
    return 0;
  }
}

/** A semente é o último passo do blueprint: a página dela só existe depois. */
async function pronto() {
  return (await http('/semente-consulta-leve/')) === 200;
}

/** Trava entre sessões: mkdir é atômico. Trava velha é de processo morto. */
function travar() {
  try {
    mkdirSync(trava);
  } catch {
    if (Date.now() - statSync(trava).mtimeMs < TRAVA_MS) {
      throw new Error('outra sessão está subindo ou trocando o WordPress local. Tente de novo em instantes.');
    }
  }
}

function destravar() {
  try {
    rmdirSync(trava);
  } catch {
    // Já saiu.
  }
}

/** Worktrees de agentes e do Playground nunca entram no git deste repositório. */
function excluirWorktrees() {
  const exclude = join(comum, 'info', 'exclude');
  mkdirSync(dirname(exclude), { recursive: true });
  const atual = existsSync(exclude) ? readFileSync(exclude, 'utf8') : '';
  if (!atual.split('\n').includes('.claude/worktrees/')) appendFileSync(exclude, `${atual.endsWith('\n') || !atual ? '' : '\n'}.claude/worktrees/\n`);
}

function garantirWorktree(ref) {
  excluirWorktrees();
  if (existsSync(join(pasta, '.git'))) return;
  mkdirSync(dirname(pasta), { recursive: true });
  git(['worktree', 'add', '--detach', pasta, ref], raiz);
}

/** Pastas `.cli` dos projetos vizinhos (mesma pasta-mãe), sem citar nomes. */
function vizinhas() {
  try {
    return readdirSync(resolve(raiz, '..'), { withFileTypes: true })
      .filter((d) => d.isDirectory())
      .map((d) => resolve(raiz, '..', d.name, 'tools', 'local', '.cli'));
  } catch {
    return [];
  }
}

/** Pasta do Playground CLI: a deste repositório ou a de um projeto vizinho. */
function pastaCli() {
  if (process.env.CONSULTA_LEVE_CLI_DIR) return resolve(process.env.CONSULTA_LEVE_CLI_DIR);
  const opcoes = [
    join(raiz, 'tools', 'local', '.cli'),
    ...vizinhas(),
  ];
  return opcoes.find((p) => existsSync(join(p, 'node_modules', '@wp-playground', 'cli'))) || opcoes[0];
}

function trocarRef(ref, quem) {
  const sujo = git(['status', '--porcelain'], pasta);
  if (sujo) throw new Error(`o worktree central tem alterações soltas, ninguém deve editar nele:\n${sujo}`);
  git(['checkout', '--quiet', '--detach', ref], pasta);
  gravarEstado({ ref, sha: git(['rev-parse', '--short', 'HEAD'], pasta), quem, usadoEm: new Date().toISOString() });
}

function imprimir(estado, situacao) {
  console.log(`[consulta-leve-local] ${situacao}`);
  console.log(`  site: ${URL_SITE}/  (painel: ${URL_SITE}/wp-admin/tools.php?page=consulta-leve)`);
  console.log(`  ref: ${estado.ref || '?'} (${estado.sha || '?'}), última sessão: ${estado.quem || '?'}, em ${estado.usadoEm || '?'}`);
  console.log(`  pasta: ${pasta}`);
  console.log(`  log: ${log}`);
  console.log(`  credencial: ${join(pasta, 'tools', 'local', 'admin.local.json')} (não é impressa)`);
}

async function status() {
  const estado = lerEstado();
  if (!(await http('/'))) {
    console.log(`[consulta-leve-local] fora do ar${vivo(estado.pid) ? ' (processo vivo, subindo ou travado)' : ''}. Suba com: node tools/local/central.mjs subir --quem <sessão>`);
    process.exitCode = 3;
    return;
  }
  imprimir(estado, (await pronto()) ? 'no ar' : 'subindo, semente ainda não terminou');
}

async function subir() {
  const quem = opcao('quem') || 'sem nome';
  const ref = opcao('ref');
  if (await http('/')) {
    if (ref) await usar(ref, quem);
    else imprimir(lerEstado(), 'já no ar, reaproveite');
    return;
  }

  travar();
  try {
    const alvo = ref || 'main';
    garantirWorktree(alvo);
    trocarRef(alvo, quem);
    const saida = openSync(log, 'a');
    const filho = spawn(process.execPath, [join(pasta, 'tools', 'local', 'start.mjs')], {
      cwd: pasta,
      detached: true,
      stdio: ['ignore', saida, saida],
      windowsHide: true,
      env: { ...process.env, CONSULTA_LEVE_CLI_DIR: pastaCli(), NODE_OPTIONS: '--max-old-space-size=3072' },
    });
    filho.unref();
    closeSync(saida);
    gravarEstado({ pid: filho.pid, iniciadoEm: new Date().toISOString() });

    console.log(`[consulta-leve-local] subindo ${alvo} em segundo plano. Espere a semente (alguns minutos)...`);
    const limite = Date.now() + ESPERA_MS;
    while (Date.now() < limite) {
      if (await pronto()) {
        imprimir(lerEstado(), 'no ar');
        return;
      }
      if (!vivo(filho.pid)) throw new Error(`o servidor parou ao subir. Veja ${log}`);
      await new Promise((ok) => setTimeout(ok, 3000));
    }
    throw new Error(`o site não ficou pronto em 12 minutos. Veja ${log}`);
  } finally {
    destravar();
  }
}

async function usar(ref, quem) {
  if (!ref) throw new Error('informe a ref: node tools/local/central.mjs usar <ref> --quem <sessão>');
  if (!quem) throw new Error('informe --quem <sessão>: é assim que as outras sessões sabem quem trocou a branch.');
  if (!(await http('/'))) throw new Error('fora do ar. Suba com: node tools/local/central.mjs subir --ref <ref> --quem <sessão>');

  git(['rev-parse', '--verify', `${ref}^{commit}`], raiz);
  const estado = lerEstado();
  const mesma = git(['rev-parse', `${ref}^{commit}`], raiz) === git(['rev-parse', 'HEAD'], pasta);
  const recente = Date.now() - new Date(estado.usadoEm || 0).getTime() < RESERVA_MS;
  if (!mesma && recente && estado.quem !== quem && !process.argv.includes('--forcar')) {
    throw new Error(`${estado.quem} usa ${estado.ref} desde ${estado.usadoEm}. Combine com essa sessão ou use --forcar.`);
  }

  travar();
  try {
    trocarRef(ref, quem);
  } finally {
    destravar();
  }
  imprimir(lerEstado(), mesma ? 'mesma versão, site compartilhado' : `trocado para ${ref}`);
}

/** Encerra a árvore do servidor (PID conferido) e confere a porta. */
async function parar() {
  const { pid } = lerEstado();
  if (eNode(pid)) {
    const r = process.platform === 'win32'
      ? spawnSync('taskkill', ['/PID', String(pid), '/T', '/F'], { encoding: 'utf8' })
      : spawnSync('kill', ['-TERM', `-${pid}`], { encoding: 'utf8' });
    if (r.status !== 0) process.kill(pid);
  } else if (pid) {
    console.log(`[consulta-leve-local] o PID ${pid} não é mais o servidor; nada encerrado por ele.`);
  }
  await new Promise((ok) => setTimeout(ok, 1500));
  if (await http('/')) throw new Error(`a porta ${PORTA} ainda responde: outro processo a ocupa. Confira os processos node.`);
  gravarEstado({ pid: 0 });
  console.log('[consulta-leve-local] encerrado.');
}

const comandos = {
  status,
  subir,
  usar: () => usar(process.argv[3] && !process.argv[3].startsWith('--') ? process.argv[3] : '', opcao('quem')),
  parar,
};

const comando = comandos[process.argv[2] || 'status'];
if (!comando) {
  console.error('Uso: node tools/local/central.mjs status | subir [--ref <ref>] [--quem <sessão>] | usar <ref> --quem <sessão> [--forcar] | parar');
  process.exit(2);
}
try {
  await comando();
} catch (erro) {
  console.error(`[consulta-leve-local] ${erro.message}`);
  process.exit(1);
}
