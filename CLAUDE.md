# Consulta Leve: regras para quem mexe no repositório

- Fonte da verdade: `docs/CONTRATO.md`. Mudou formato, gancho ou regra,
  mude o contrato no mesmo PR.
- Uma branch por frente, commit por assunto (Conventional Commits em
  português), PR pequeno. Nada direto na `main`.
- Prefixos fixos: `consulta_leve_`, `CONSULTA_LEVE_`, índices `cl_`, REST
  `consulta-leve/v1`. Text domain `consulta-leve`. Textos em português do
  Brasil com acentos corretos; sem travessão longo.
- Complementar, nunca sobrescrever o que o tema já faz (contrato, seção 4).
- Soft delete: nunca apagar post, meta, opção de terceiros ou dado. Só
  índice `cl_` próprio pode sair.
- SQL sempre com `$wpdb->prepare`; tabela e coluna de lista fechada.
- Escrita no painel: `manage_options` + nonce. Saída escapada.
- Sem biblioteca, sem build, sem CDN, sem `eval`, sem `base64_decode`.
- Antes de commitar: `node tests/run.js` verde.
- WordPress local: um só Playground central
  (`node tools/local/central.mjs subir --quem <sessão>`, porta 9470).
  Encerre com `parar` ao terminar.
- Segredo nunca no repositório (chave de assinatura fica fora, ver
  `docs/RELEASE.md`).
