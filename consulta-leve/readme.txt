=== Consulta Leve ===
Contributors: samuelmarmelo
Tags: performance, database, search, query, cache
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Consultas leves ao banco do WordPress para sites com milhares de posts, sem conflitar com o que o tema já faz.

== Description ==

* Cache versionado: invalidação por versão e pelas mudanças do próprio WordPress, sem varrer wp_options.
* Paginação por cursor, sem OFFSET, e argumentos enxutos para WP_Query.
* Carga em lote de posts, metas, termos, capas, autores e opções (sem consulta dentro de laço).
* Tabela de busca própria com FULLTEXT e reserva LIKE, mantida em dia sozinha.
* Índices conferidos em wp_posts e wp_postmeta: nada é criado sem você ver o EXPLAIN.
* Busca do site contida, noindex na página de busca e limite por IP.
* Rota REST de busca rápida com ETag e shortcode que funciona sem script.
* Cron real opcional: desliga o WP-Cron das visitas sem editar o wp-config.php e volta sozinho se o cron da hospedagem parar.
* Perfil de consultas por cabeçalho e diagnóstico do banco com recomendações.

== Installation ==

1. Envie o zip em Plugins, Adicionar, Enviar plugin.
2. Ative e confira o diagnóstico em Ferramentas, Consulta Leve.

== Changelog ==

Ver CHANGELOG.md no repositório.
