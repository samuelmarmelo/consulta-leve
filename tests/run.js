'use strict';

// Suíte da Consulta Leve (docs/CONTRATO.md, seção 16). Uso: node tests/run.js
// 1. php -l em todo .php (quando há php no PATH);
// 2. node --check em todo .js e .mjs;
// 3. contratos estáticos tests/validate-*-contract.js;
// 4. testes PHP sem WordPress tests/php/*.test.php (quando há php).
// Sai com código 1 se algo falhar.
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const raiz = path.resolve(__dirname, '..');
const ignorar = new Set(['.git', 'node_modules', '.claude', 'dist']);

function listar(pasta, extensoes, saida = []) {
  for (const nome of fs.readdirSync(pasta)) {
    if (ignorar.has(nome) || nome.startsWith('.cli') || nome === '.logs') continue;
    const caminho = path.join(pasta, nome);
    const info = fs.lstatSync(caminho);
    if (info.isDirectory()) listar(caminho, extensoes, saida);
    else if (extensoes.some((e) => nome.endsWith(e))) saida.push(caminho);
  }
  return saida;
}

const temPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;
const falhas = [];
let feitos = 0;

if (temPhp) {
  for (const arquivo of listar(raiz, ['.php'])) {
    feitos++;
    const r = spawnSync('php', ['-l', arquivo], { encoding: 'utf8' });
    if (r.status !== 0) falhas.push(`php -l ${path.relative(raiz, arquivo)}: ${(r.stdout + r.stderr).trim().split('\n')[0]}`);
  }
} else {
  console.log('php ausente: php -l e testes PHP pulados');
}

for (const arquivo of listar(raiz, ['.js', '.mjs'])) {
  feitos++;
  const r = spawnSync(process.execPath, ['--check', arquivo], { encoding: 'utf8' });
  if (r.status !== 0) falhas.push(`node --check ${path.relative(raiz, arquivo)}: ${r.stderr.trim().split('\n')[0]}`);
}

const pastaTestes = path.join(raiz, 'tests');
for (const nome of fs.readdirSync(pastaTestes).filter((f) => /^validate-.*-contract\.js$/.test(f)).sort()) {
  feitos++;
  const r = spawnSync(process.execPath, [path.join(pastaTestes, nome)], { cwd: raiz, encoding: 'utf8' });
  if (r.status !== 0) {
    const saida = `${r.stderr || ''}\n${r.stdout || ''}`;
    const motivo = saida.split('\n').find((l) => /Error|assert|expected|falh/i.test(l)) || saida.trim().split('\n')[0] || '';
    falhas.push(`${nome}: ${motivo.trim().slice(0, 300)}`);
  }
}

if (temPhp) {
  const pastaPhp = path.join(pastaTestes, 'php');
  for (const nome of fs.readdirSync(pastaPhp).filter((f) => f.endsWith('.test.php')).sort()) {
    feitos++;
    const r = spawnSync('php', ['-d', 'error_reporting=E_ALL', '-d', 'display_errors=stderr', path.join(pastaPhp, nome)], { cwd: raiz, encoding: 'utf8' });
    process.stdout.write(r.stdout);
    if (r.status !== 0) falhas.push(`${nome}: ${(r.stderr || '').trim().split('\n').slice(0, 6).join(' | ').slice(0, 600)}`);
  }
}

console.log(`${feitos - falhas.length}/${feitos} verificações verdes`);
for (const f of falhas) console.log(`FALHOU ${f}`);
process.exit(falhas.length ? 1 : 0);
