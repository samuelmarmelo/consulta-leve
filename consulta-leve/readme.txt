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
* Cabeçalhos de segurança opcionais, desligados por padrão, sem trocar o que o site já envia.
* Os mesmos cabeçalhos também no .htaccess (Apache ou LiteSpeed), opcional: ensaio numa pasta à parte, bloco próprio, conferência no site e volta automática.
* Perfil de consultas por cabeçalho e diagnóstico do banco com recomendações.

== Installation ==

1. Envie o zip em Plugins, Adicionar, Enviar plugin.
2. Ative e confira o diagnóstico em Ferramentas, Consulta Leve.

== Frequently Asked Questions ==

= Liguei os cabeçalhos no .htaccess e o site deu erro. Como volto? =

Por FTP ou SSH, abra o .htaccess da pasta do site e apague as linhas de # BEGIN Consulta Leve até # END Consulta Leve, incluindo as duas. Não mexa no resto. Com WP-CLI, qualquer comando desfaz uma escrita interrompida, até 5 minutos depois.

== Changelog ==

Ver CHANGELOG.md no repositório.
