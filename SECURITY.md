# Segurança

## Como relatar uma falha

Não abra issue pública. Use **Security, Report a vulnerability** neste
repositório (relato privado). Diga a versão, o passo a passo e o impacto.
A resposta inicial sai em até 7 dias.

## Versões com correção

Só a última versão publicada recebe correção. O plugin se atualiza sozinho
pelas versões assinadas (ver `docs/ATUALIZADOR.md`).

## O que o plugin promete

- **Nada muda o banco sem quem administra pedir.** Índices e a tabela de
  busca começam desligados; índice só é criado por clique no painel (nonce +
  `manage_options`) ou por WP-CLI, depois de mostrar o EXPLAIN.
- **Soft delete.** O plugin nunca apaga post, meta, comentário, usuário ou
  opção de terceiros. Só remove índice com prefixo `cl_` criado por ele e
  linhas da própria tabela de busca, que é cache.
- **SQL sempre preparado.** `$wpdb->prepare` em todo valor; nome de tabela,
  índice e coluna sai de lista fechada no código. O perfil roda EXPLAIN só
  de SELECT que o próprio WordPress executou na requisição, nunca de SQL
  vindo de fora.
- **Rota pública mínima.** `GET /wp-json/consulta-leve/v1/busca` começa
  desligada; ligada, só devolve post publicado, sem senha, de tipo público e
  pesquisável, com teto de 20 itens, entrada validada por esquema, cursor
  assinado (HMAC) e limite por IP (429).
- **Dado de uma pessoa nunca vai para outra.** Cache com escopo `usuario`
  separa por ID e papéis; a busca do site pela tabela só vale para
  visitante.
- **Perfil só para administrador.** Cabeçalho próprio + cookie de login de
  `manage_options` conferido duas vezes; resposta `no-store`; nada gravado.
- **Saída escapada** em todo o painel; avisos por código de lista fechada;
  o script da busca rápida monta tudo com `textContent` e só aceita links
  http(s).
- **Sem `eval`, sem `base64_decode`, sem `unserialize` de entrada, sem
  drop-in, sem mexer em `wp-config.php`.** O contrato
  `tests/validate-base-contract.js` falha se alguma dessas funções aparecer.
- **Cron real sem risco de parar o site.** Desligado por padrão; liga só por
  clique (nonce + `manage_options`) e com cron externo visto há menos de 30
  minutos. Nunca altera arquivo nem constante já definida. O wp-cron.php é
  público, então uma chamada anônima pode contar como sinal de vida; ela
  também roda os eventos vencidos, e sem sinal por 60 minutos o WP-Cron das
  visitas volta.
- **Cabeçalhos de segurança só por escolha.** Todos desligados por padrão,
  valores fixos no código, nunca trocam cabeçalho existente nem tocam
  arquivo do servidor. HSTS com subdomínios e preload exige confirmação.
  Não alcançam arquivo estático nem cache que não passa pelo PHP.
- **Nenhuma chamada externa** além do atualizador, que lê o GitHub sem
  token e sem o endereço do site.

## Cadeia de entrega

- Cada versão publica o zip, o `.sha256` e a assinatura Ed25519 (`.sig`).
  O plugin só instala o que confere com as chaves de
  `consulta-leve/atualizacao/chaves.php`.
- A chave privada fica fora do repositório e de pasta sincronizada, na
  máquina de quem publica, protegida por senha (`docs/RELEASE.md`).
- A atualização automática só instala versão publicada há 48 h ou mais.
- O CI roda com permissão só de leitura e ações fixadas por commit.
- Proteções do repositório (Dependabot, varredura de segredos com bloqueio
  no push, relato privado, `main` sem force-push, tags `v*` imutáveis)
  aplicadas por `node tools/protecoes.mjs aplicar`; as que exigem
  repositório público ficam pendentes até a abertura.
- Commits com e-mail noreply; nenhum segredo no repositório (`.gitignore`
  recusa `.env`, `*.pem`, `*.key` e credenciais locais).
