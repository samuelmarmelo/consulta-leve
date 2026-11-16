# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).
Versões: [SemVer](https://semver.org/lang/pt-BR/).

## [Não publicado]

## [0.1.0] - 2026-11-16

Primeira versão.

### Adicionado
- Núcleo: carregador SafeWidget, configuração saneada, coexistência com o
  tema, trava por opção, cache versionado e registro de ações.
- Consultas: argumentos enxutos por perfil, teto para `-1`, carga em lote,
  paginação por cursor assinado, contagens em cache e lotes por cursor de ID.
- Índices conferidos em `wp_posts` e `wp_postmeta`, criados só à mão.
- Tabela de busca própria com FULLTEXT e reserva LIKE.
- Busca do site contida, `noindex`, limite por IP, rota REST de busca
  rápida com ETag e shortcode `[consulta_leve_busca]`.
- Perfil de consultas por cabeçalho, diagnóstico do banco, contador na
  barra do admin, painel em Ferramentas e comandos WP-CLI.
- Atualizador por versões assinadas (Ed25519) e release assinada.
