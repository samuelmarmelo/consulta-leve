# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).
Versões: [SemVer](https://semver.org/lang/pt-BR/).

## [Não publicado]

### Adicionado
- Cron real: chave opcional, desligada por padrão, que desliga o WP-Cron das
  visitas sem editar o `wp-config.php`. Só liga com um cron externo visto nos
  últimos 30 minutos e volta sozinha ao WP-Cron das visitas se ele sumir por
  60 minutos.
- Diagnóstico: `cron_origem` e `cron_estado` dizem se `DISABLE_WP_CRON` veio
  do `wp-config.php` ou deste plugin; alerta de cron externo parado.
- Cabeçalhos de segurança opcionais, todos desligados por padrão e enviados
  pelo PHP: `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`,
  `Content-Security-Policy: upgrade-insecure-requests` e HSTS. Cabeçalho já
  presente nunca é trocado; o tema dispensa com `cabecalhos`.

### Alterado
- O formulário geral do painel não muda chaves com formulário próprio.

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
