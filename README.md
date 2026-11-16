# Consulta Leve

**Consultas leves ao banco do WordPress para sites com milhares de posts.**
Cache versionado, paginação por cursor, carga em lote, tabela de busca com
FULLTEXT, índices conferidos e diagnóstico do banco, num plugin que
**complementa** o que o tema já faz, sem brigar com ele.

[![testes](https://github.com/samuelmarmelo/consulta-leve/actions/workflows/testes.yml/badge.svg)](https://github.com/samuelmarmelo/consulta-leve/actions/workflows/testes.yml)
[![Licença GPL-2.0-or-later](https://img.shields.io/badge/licen%C3%A7a-GPL--2.0--or--later-blue)](LICENSE)
[![WordPress 6.3+](https://img.shields.io/badge/WordPress-6.3%2B-21759b?logo=wordpress)](https://wordpress.org/)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![sem dependências](https://img.shields.io/badge/depend%C3%AAncias-nenhuma-brightgreen)](#)
[![versões assinadas](https://img.shields.io/badge/vers%C3%B5es-assinadas%20Ed25519-informational)](docs/RELEASE.md)

> English summary: **Consulta Leve** ("light query") is a dependency-free
> WordPress plugin for database performance on large sites: versioned
> object/transient caching, keyset (cursor) pagination for `WP_Query`, batch
> cache priming against N+1 queries, an own search index table with MySQL
> FULLTEXT and a LIKE fallback, safe opt-in indexes for `wp_posts` and
> `wp_postmeta`, contained core search, a rate-limited REST search endpoint
> with ETag/304, a header-triggered query profiler with EXPLAIN, and a
> read-only database health report. Every method complements what the theme
> already does instead of overriding it.

## Por que existe

Um CRM feito em WordPress passa de 5 mil demandas e recebe de 50 a 100 por
dia; duas lojas WooCommerce somam dezenas de milhares de produtos e
variações. Os três rodam rápidos em hospedagem compartilhada, sem Redis,
porque seguem as mesmas regras de consulta. A Consulta Leve junta essas
regras num plugin genérico:

| Antes | Depois (medido nos projetos de origem) |
|---|---|
| quadro lia 5.914 linhas, 199 ms | 435 linhas, 22,7 ms (janela por índice próprio) |
| `GET /board` 4.394 ms | 1.492 ms (carga em lote e índices compostos) |
| home da loja com 82 consultas | 56 (opções carregadas em lote) |
| Redis mal configurado: +1 s por pedido | diagnóstico mede a latência e avisa |

## O que ela faz

| Método | Começa | O que muda |
|---|---|---|
| Argumentos enxutos (`consulta_leve_args`) | ligado | sem contagem, sem metas e termos que a lista não usa, teto em `-1` |
| Carga em lote (`consulta_leve_primar`) | ligado | posts, metas, termos, capas, autores e pais numa consulta por tipo |
| Opções em lote | ligado | `wp_prime_option_caches` da lista que o perfil aponta |
| Cache versionado (`consulta_leve_lembrar`) | ligado | invalida por versão e pelas mudanças do WordPress, sem varrer `wp_options` |
| Paginação por cursor (`consulta_leve_cursor_args`) | ligado | página N custa o mesmo que a primeira, sem OFFSET |
| Lotes por cursor de ID (`consulta_leve_em_lotes`) | ligado | tarefas em massa com orçamento de tempo, retomáveis |
| Busca do site contida | ligado | termo encurtado, termo curto sem banco, colunas estreitadas, `noindex`, limite por IP |
| Tabela de busca com FULLTEXT | desligado | busca lê uma cópia indexada e enxuta, mantida em dia sozinha |
| Rota REST de busca rápida | desligado | resposta enxuta, cursor, cache de 60 s, ETag com 304 |
| Índices em `wp_posts` e `wp_postmeta` | manual | só com clique, depois do EXPLAIN; nunca duplica índice existente |
| Perfil de consultas | por cabeçalho | consultas por forma, N+1, EXPLAIN, quem chamou |
| Diagnóstico do banco | ligado | cache de objetos, autoload, tabelas, revisões, cron, OPcache |

Detalhes, formatos e ganchos: [`docs/CONTRATO.md`](docs/CONTRATO.md).

## Convive com o tema

Nenhum método sobrescreve o que o tema ou outro plugin já definiu:
argumentos entram como `$args + padrões`, colunas de busca por interseção,
índice com as mesmas colunas de outro nome conta como "coberto", tipos que
o tema já indexa ficam fora da tabela de busca. O tema pode dispensar
qualquer método:

```php
add_theme_support( 'consulta-leve', array( 'dispensar' => array( 'busca', 'monitor' ) ) );
```

## Instalação

1. Baixe `consulta-leve-vX.Y.Z.zip` da [última versão](https://github.com/samuelmarmelo/consulta-leve/releases/latest).
2. Plugins, Adicionar, Enviar plugin. Ative.
3. Abra Ferramentas, Consulta Leve: o diagnóstico mostra onde o banco pesa.

As atualizações seguintes chegam pelo painel, conferidas por SHA-256 e
assinatura Ed25519 (`docs/ATUALIZADOR.md`).

## Para quem desenvolve o tema

```php
// Lista sem contagem nem metas, com cache que se invalida sozinho.
$ids = consulta_leve_lembrar( 'vitrine', array( 'cat' => 12 ), function () {
	$q = new WP_Query( consulta_leve_args( array( 'post_type' => 'product', 'cat' => 12, 'posts_per_page' => 24 ), 'ids' ) );
	return $q->posts;
}, array( 'deps' => array( 'posts', 'terms' ) ) );

// Antes do laço: tudo que o card usa, em lote.
consulta_leve_primar( $ids, array( 'miniaturas' => true, 'autores' => true ) );

// Paginação por cursor.
$q = new WP_Query( consulta_leve_cursor_args( array( 'post_type' => 'post', 'posts_per_page' => 20 ), $cursor ) );
$pagina = consulta_leve_cursor_resultado( $q ); // ['posts' => [...], 'proximo' => '...']

// Tarefa em massa retomável.
$estado = consulta_leve_em_lotes( array( 'tipos' => 'post', 'cursor' => $cursor ), function ( $ids ) {
	// ...
} );
```

Busca rápida em qualquer página: `[consulta_leve_busca tipos="post,page" limite="8"]`
(com a rota REST ligada; sem ela, vira um formulário de busca comum).

Medir uma página (logado como administrador, no console do navegador):

```js
fetch(location.href, { headers: { 'X-Consulta-Leve-Perfil': 'explain' } })
  .then((r) => console.log(JSON.parse(atob(r.headers.get('X-Consulta-Leve-Perfil')))));
```

WP-CLI: `wp consulta-leve diagnostico`, `wp consulta-leve indices listar`,
`wp consulta-leve busca reconstruir`.

## Estrutura

| Pasta | O que tem |
|---|---|
| `consulta-leve/` | o plugin (é esta pasta que vai no zip) |
| `docs/` | contrato, atualizador e releases |
| `tests/` | contratos Node e testes PHP sem WordPress |
| `tools/` | release assinada, chave, proteções do repositório e o WordPress local central |

## Testes e WordPress local

```bash
node tests/run.js
```

```bash
node tools/local/central.mjs subir --quem <sessão>
```

Um só Playground para todas as sessões, com 6 mil posts de exemplo, na
porta 9470. Encerre com `node tools/local/central.mjs parar`.

## Segurança

Tudo que muda o banco começa desligado, SQL sempre preparado, rota pública
mínima e soft delete. Falhas: relate em particular, como descrito em
[`SECURITY.md`](SECURITY.md).

## Licença

GPL-2.0-or-later. Ver [`LICENSE`](LICENSE).
