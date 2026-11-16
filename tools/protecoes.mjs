#!/usr/bin/env node
/**
 * Proteções do repositório no GitHub, aplicadas pela API (sem gh).
 *
 *   node tools/protecoes.mjs            mostra o que está ligado
 *   node tools/protecoes.mjs aplicar    liga o que o plano da conta permite
 *
 * Liga: alertas de dependência e correções automáticas do Dependabot;
 * varredura de segredos com bloqueio no push; relato privado de falhas;
 * regra da main (sem apagar, sem force-push); tags v* imutáveis. Varredura
 * de segredos, relato privado e regras só existem em repositório público
 * (ou conta paga): o que o GitHub recusar aparece como "indisponível" e o
 * resto segue.
 *
 * Token (nunca impresso): GITHUB_TOKEN, GH_TOKEN ou o do Git
 * (`git credential fill`), com administração do repositório.
 */
import { spawnSync } from 'node:child_process';

const REPO = 'samuelmarmelo/consulta-leve';
const API = 'https://api.github.com';

function token() {
  if (process.env.GITHUB_TOKEN) return process.env.GITHUB_TOKEN;
  if (process.env.GH_TOKEN) return process.env.GH_TOKEN;
  const r = spawnSync('git', ['credential', 'fill'], { input: 'protocol=https\nhost=github.com\n\n', encoding: 'utf8' });
  const linha = (r.stdout || '').split('\n').find((l) => l.startsWith('password='));
  if (!linha) throw new Error('sem token: defina GITHUB_TOKEN ou entre no GitHub pelo Git');
  return linha.slice('password='.length).trim();
}

const TOKEN = token();

async function api(metodo, caminho, corpo) {
  const r = await fetch(`${API}${caminho}`, {
    method: metodo,
    headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'consulta-leve-protecoes' },
    body: corpo ? JSON.stringify(corpo) : undefined,
  });
  const texto = await r.text();
  let dados = null;
  try {
    dados = texto ? JSON.parse(texto) : null;
  } catch {
    dados = null;
  }
  return { ok: r.ok, status: r.status, dados };
}

async function passo(nome, fazer) {
  const r = await fazer();
  const situacao = r.ok ? 'ligado' : r.status === 403 || r.status === 422 || r.status === 404 ? 'indisponível neste plano ou repositório privado' : `falhou (HTTP ${r.status})`;
  console.log(`${nome}: ${situacao}`);
}

async function regra(nome, corpo) {
  const atuais = await api('GET', `/repos/${REPO}/rulesets`);
  if (atuais.ok && Array.isArray(atuais.dados) && atuais.dados.some((x) => x.name === nome)) return { ok: true, status: 200 };
  return api('POST', `/repos/${REPO}/rulesets`, corpo);
}

async function aplicar() {
  await passo('alertas de dependência', () => api('PUT', `/repos/${REPO}/vulnerability-alerts`));
  await passo('correções automáticas do Dependabot', () => api('PUT', `/repos/${REPO}/automated-security-fixes`));
  await passo('varredura de segredos e bloqueio no push', () => api('PATCH', `/repos/${REPO}`, { security_and_analysis: { secret_scanning: { status: 'enabled' }, secret_scanning_push_protection: { status: 'enabled' } } }));
  await passo('relato privado de falhas', () => api('PUT', `/repos/${REPO}/private-vulnerability-reporting`));
  await passo('main sem apagar e sem force-push', () => regra('main protegida', { name: 'main protegida', target: 'branch', enforcement: 'active', conditions: { ref_name: { include: ['~DEFAULT_BRANCH'], exclude: [] } }, rules: [{ type: 'deletion' }, { type: 'non_fast_forward' }] }));
  await passo('tags v* imutáveis', () => regra('tags de versão imutáveis', { name: 'tags de versão imutáveis', target: 'tag', enforcement: 'active', conditions: { ref_name: { include: ['refs/tags/v*'], exclude: [] } }, rules: [{ type: 'deletion' }, { type: 'non_fast_forward' }, { type: 'update' }] }));
}

async function mostrar() {
  const repo = await api('GET', `/repos/${REPO}`);
  if (!repo.ok) throw new Error(`não li o repositório (HTTP ${repo.status})`);
  console.log(`${REPO}: ${repo.dados.private ? 'privado' : 'público'}`);
  console.log(`segurança: ${JSON.stringify(repo.dados.security_and_analysis || {})}`);
  const regras = await api('GET', `/repos/${REPO}/rulesets`);
  console.log(`regras: ${regras.ok ? regras.dados.map((x) => x.name).join(', ') || 'nenhuma' : 'indisponíveis neste plano ou repositório privado'}`);
}

try {
  await (process.argv[2] === 'aplicar' ? aplicar() : mostrar());
} catch (erro) {
  console.error(`protecoes: ${erro.message}`);
  process.exit(1);
}
