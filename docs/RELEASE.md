# Releases da Consulta Leve

Contrato da publicação. O atualizador do plugin (`docs/ATUALIZADOR.md`) só
instala o que segue este formato. Mudou o formato, mude os dois lados no
mesmo PR e suba a versão do formato (`consulta-leve-release-v2`).

## 1. Arquivos de cada versão

Cada versão `vX.Y.Z` (SemVer, sempre maior que a anterior) publica três
arquivos, enviados com a versão ainda em rascunho:

| Arquivo | Conteúdo |
|---|---|
| `consulta-leve-vX.Y.Z.zip` | o plugin, pasta `consulta-leve/` na raiz, montado com `git archive` do commit da tag |
| `consulta-leve-vX.Y.Z.zip.sha256` | uma linha: `<sha256 em hex minúsculo>  consulta-leve-vX.Y.Z.zip` (formato do `sha256sum`) |
| `consulta-leve-vX.Y.Z.zip.sig` | uma linha: assinatura Ed25519 (64 bytes) em hex minúsculo (128 caracteres) da mensagem da seção 2 |

O repositório tem releases imutáveis e tags `v*` protegidas: depois de
publicada, nenhuma versão muda de arquivo nem de commit.

## 2. Mensagem assinada

Texto UTF-8, linhas terminadas em `\n`, nesta ordem:

```
consulta-leve-release-v1
tag: vX.Y.Z
arquivo: consulta-leve-vX.Y.Z.zip
sha256: <sha256 do zip em hex minúsculo>
```

A tag e o nome do arquivo entram na mensagem: uma assinatura válida para
uma versão não serve para outra.

## 3. Chave de assinatura

- Ed25519. Chave privada fora de qualquer repositório e de pasta
  sincronizada com a nuvem (a ferramenta recusa, inclusive por junction ou
  link): `CONSULTA_LEVE_CHAVE_ASSINATURA` ou
  `~/.consulta-leve/chave-assinatura-ed25519.pem` (`node tools/chave.mjs gerar`).
- Com senha: `CONSULTA_LEVE_CHAVE_SENHA` com 16 caracteres ou mais e
  `node tools/chave.mjs proteger` (scrypt N 2^17 deriva a chave de
  AES-256-GCM; arquivo `consulta-leve-chave-v1`). A senha e o token nunca chegam
  aos processos que a release abre (testes, git). A release pede a mesma variável
  para assinar. Guarde a chave e a senha num cofre de senhas, separadas.
- Chaves públicas confiáveis (32 bytes em hex minúsculo):
  `consulta_leve_atualizador_chaves()` em `consulta-leve/atualizacao/chaves.php`. A release recusa assinar com chave que
  não esteja lá.
- Troca de chave: publicar uma versão assinada pela chave antiga que já
  traga a nova em `chaves.php`; na versão seguinte, assinar com a nova e
  tirar a antiga da lista.
- Chave vazada: apagar no GitHub qualquer versão que você não publicou
  (a atualização automática espera 48 h, então há tempo), depois tirar a
  chave da lista numa versão nova assinada com uma chave que continua
  confiável. Se não houver nenhuma, os sites precisam receber o zip novo à
  mão (Plugins, Adicionar, Enviar plugin).

## 4. Como publicar

```bash
node tests/run.js
node tools/release.mjs --dry-run
node tools/release.mjs
```

Antes: subir `Version` e `CONSULTA_LEVE_VERSAO` em `consulta-leve/consulta-leve.php`, o
`Stable tag` de `consulta-leve/readme.txt` e escrever a seção `## [X.Y.Z]` do
`CHANGELOG.md`, num commit próprio já na `main`.

A ferramenta para em qualquer falha antes de publicar: branch diferente da
`main`, mudança local, HEAD diferente de `origin/main`, tag já usada,
versões diferentes entre os arquivos (ou fora do formato que o plugin
aceita), suíte vermelha, `export-ignore`/`export-subst` no
`.gitattributes`, link simbólico, lista do zip gerado diferente da árvore,
arquivo de desenvolvimento no zip, zip acima de 5 MB, chave fora da lista.
Depois de enviar, confere nome, tamanho e SHA-256 de cada arquivo no
rascunho (pelo `digest` da API ou baixando o arquivo) e só então publica
como a versão mais recente. Por fim baixa a versão publicada como um site
baixaria, sem token, e confere SHA-256 e assinatura (até 3 tentativas);
se falhar, avisa. Os sites recusam pacote que não confere; com versões
imutáveis, a saída é apagar a versão (a tag fica reservada) e publicar uma
versão maior.

Token: `GITHUB_TOKEN`, `GH_TOKEN` ou o do Git (`git credential fill`), com
escrita em Contents. Nunca é impresso nem gravado.
