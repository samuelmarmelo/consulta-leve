# Contrato da Consulta Leve

Fonte da verdade do plugin. Mudou um formato, um gancho ou uma regra daqui,
mude o contrato no mesmo PR.

## 1. O que o plugin é

Plugin de WordPress que junta, num lugar só e de forma genérica, os métodos
de consulta ao banco que deixam um CRM interno (mais de 5 mil demandas,
50 a 100 novas por dia) e duas lojas WooCommerce (uma com 20 mil produtos
e variações) rápidos em hospedagem
compartilhada, sem Redis e sem servidor dedicado.

- Nome: **Consulta Leve**. Slug e pasta `consulta-leve`, arquivo principal
  `consulta-leve/consulta-leve.php`, text domain `consulta-leve`.
- Prefixos: funções, opções, ganchos e transients `consulta_leve_`;
  constantes `CONSULTA_LEVE_`; índices próprios `cl_`; REST
  `consulta-leve/v1`; classes CSS `cl-`; handles `consulta-leve-`.
- Requisitos: WordPress 6.3+, PHP 7.4+. WooCommerce opcional.
- Licença GPL-2.0-or-later.

## 2. Princípios

1. **Medir antes e depois.** O perfil (seção 10.1) mostra consultas
   agrupadas por forma, tempo e EXPLAIN. Toda otimização registra os dois
   números.
2. **Complementar, nunca brigar com o tema** (seção 4). Nenhum método
   sobrescreve um valor que o tema ou outro plugin já definiu, nem reescreve
   SQL de terceiros.
3. **Seguro por padrão.** Tudo que muda o banco (índice, tabela de busca) ou
   abre superfície pública (REST, busca rápida) começa desligado. O que só
   lê ou só acrescenta argumentos ausentes começa ligado.
4. **SafeWidget.** Cada módulo é um arquivo independente; arquivo ausente é
   pulado; erro vira a ação `consulta_leve_falha` e o site segue.
5. **Sem dependência do índice para funcionar.** Tabela de busca ausente,
   em reconstrução ou com FULLTEXT recusado: volta ao caminho do núcleo.
6. **Cache com invalidação clara.** Chave versionada, nunca varredura de
   transients. Dado de um usuário nunca servido a outro.
7. **Soft delete.** O plugin nunca apaga post, meta, opção de terceiros ou
   linha de dado. O único DROP permitido é o de índice com prefixo `cl_`
   criado por ele mesmo.
8. **Sem biblioteca, sem build, sem CDN.**

## 3. Base

### 3.1 Carregador

`consulta-leve.php` define `CONSULTA_LEVE_VERSAO`, `CONSULTA_LEVE_ARQUIVO`
e `CONSULTA_LEVE_DIR` e carrega a lista de `consulta_leve_modulos()`
(filtro `consulta_leve_modulos`). Nome de módulo só passa pela regra
`^[a-z0-9_-]+(/[a-z0-9_-]+)*\.php$`. A guarda `CONSULTA_LEVE_VERSAO`
permite embutir a pasta num tema ou plugin: a primeira cópia vence.

Ações: `consulta_leve_carregado` (fim da carga), `consulta_leve_falha`
(`$modulo`, `$erro`).

### 3.2 Configuração (`nucleo/config.php`)

Uma opção `consulta_leve_config` (array pequeno, autoload ligado).
`consulta_leve_config()` devolve os padrões mesclados com a opção e com o
filtro `consulta_leve_config`; `consulta_leve_opcao( $chave )` lê uma
chave. Valores passam por `consulta_leve_config_sanitizar()` (lista fechada
de chaves, tipos e limites). Chaves:

| Chave | Tipo | Padrão | Uso |
|---|---|---|---|
| `argumentos` | bool | true | argumentos enxutos nas consultas marcadas (5.1) |
| `teto_global` | bool | false | teto em `posts_per_page => -1` fora do painel (5.1) |
| `teto` | int 50 a 5000 | 500 | valor do teto |
| `primar_opcoes` | bool | true | carga em lote de opções sem autoload (5.2) |
| `opcoes_lote` | string[] | [] | nomes extras para a carga em lote |
| `busca` | bool | true | busca do núcleo contida (8.1) |
| `busca_min` | int 1 a 5 | 2 | letras mínimas |
| `busca_max` | int 20 a 200 | 80 | letras máximas |
| `busca_palavras` | int 1 a 12 | 6 | palavras máximas |
| `busca_colunas` | `nucleo` ou `titulo_resumo` ou `titulo` | `nucleo` | colunas da busca do núcleo |
| `busca_noindex` | bool | true | `noindex` nas páginas de busca |
| `busca_limite` | int 0 a 600 | 30 | buscas por minuto por IP no site (0 desliga) |
| `indice_busca` | bool | false | tabela de busca própria (7) |
| `indice_tipos` | string[] | [] | tipos de post indexados |
| `indice_metas` | string[] | [] | chaves de meta copiadas para o texto |
| `indice_na_busca` | bool | false | busca do núcleo lê a tabela (7.5) |
| `rest` | bool | false | rota pública de busca rápida (9) |
| `rest_por_minuto` | int 10 a 600 | 60 | pedidos por minuto por IP |
| `monitor` | bool | true | contador na barra do admin (10.3) |
| `cache_ttl` | int 30 a 86400 | 600 | TTL padrão do cache (6) |

### 3.3 Coexistência (`nucleo/coexistencia.php`)

Ver seção 4.

### 3.4 Trava (`nucleo/trava.php`)

`consulta_leve_travar( $nome, $ttl )` grava `expira|dono` com `add_option`
(autoload desligado) e relê do banco para confirmar o dono; devolve o dono
ou `''`. Trava vencida é removida e tentada de novo uma vez.
`consulta_leve_liberar( $nome, $dono )` só apaga com `hash_equals` no dono.
Funciona em MySQL e no SQLite do Playground (sem `GET_LOCK`). Origem:
CRM interno `core/trava.php` e painel de catálogo `core/trava.php`.

## 4. Coexistência com o tema e outros plugins

Regra geral: **o plugin complementa**. Cada método tem a sua forma de não
aplicar duas vezes:

| Método | Como complementa |
|---|---|
| Argumentos enxutos | `$args + padrões`: chave que o chamador definiu nunca muda |
| Teto de `-1` | só em consulta sem `consulta_leve_sem_teto`, fora do painel, fora do REST e do WP-CLI, desligado por padrão |
| Carga de caches em lote | as funções do núcleo pulam o que já está em cache: idempotente |
| Carga de opções em lote | `wp_prime_option_caches()` pula opção já carregada; desliga com cache de objetos externo |
| Cache versionado | prefixo e grupo próprios; nunca lê nem invalida cache alheio |
| Índices | antes de criar, procura índice com as mesmas colunas iniciais, de qualquer nome; achou, marca "já coberto" e não cria |
| Tabela de busca | tabela própria; tipo de post que o tema já indexa entra em `consulta_leve_indice_tipos_cobertos` e é pulado |
| Busca do núcleo | colunas por interseção (nunca alarga o que o tema estreitou); termo só encurta |
| `noindex` | `wp_robots` mescla arrays: repetir é inofensivo |
| Perfil | cabeçalho próprio `X-Consulta-Leve-Perfil`; `SAVEQUERIES` só se ninguém definiu |
| Monitor | some quando o Query Monitor está ativo |

Dispensa explícita (o tema diz "este eu já faço"):

```php
add_theme_support( 'consulta-leve', array( 'dispensar' => array( 'busca', 'monitor' ) ) );
```

ou o filtro `consulta_leve_dispensar` (`bool $dispensar, string $metodo`).
`consulta_leve_dispensado( $metodo )` responde às duas formas e aos
detectores automáticos (filtro `consulta_leve_detectores`, mapa
`metodo => callable`). Detectores de fábrica: `monitor` (Query Monitor
ativo) e `indice_busca` para os tipos que o CRM interno já indexa
(`crm_demanda`, quando `crmimpactei_busca_tabela()` existe).

Métodos que aceitam dispensa: `argumentos`, `teto_global`,
`primar_opcoes`, `busca`, `busca_noindex`, `busca_limite`,
`indice_busca`, `rest`, `monitor`, `perfil`.

## 5. Consultas (`consultas/`)

### 5.1 Argumentos enxutos (`argumentos.php`)

`consulta_leve_args( array $args, string $perfil = 'lista' )` devolve
`$args + padrões do perfil`:

| Perfil | Padrões |
|---|---|
| `ids` | `fields => ids`, `no_found_rows => true`, `update_post_meta_cache => false`, `update_post_term_cache => false`, `ignore_sticky_posts => true` |
| `lista` | `no_found_rows => true`, `ignore_sticky_posts => true` |
| `leve` | `lista` + `update_post_meta_cache => false`, `update_post_term_cache => false` |
| `paginada` | `ignore_sticky_posts => true` (contagem mantida) |
| `contagem` | `fields => ids`, `posts_per_page => 1`, `no_found_rows => false`, caches desligados |

Mesmo efeito sem chamar a função: `new WP_Query( array( 'consulta_leve' => 'ids', ... ) )`.
O `pre_get_posts` (prioridade 1) só mexe em consulta com essa marca.

Teto: `posts_per_page => -1` em consulta marcada vira `teto` (padrão 500),
salvo `consulta_leve_sem_teto => true`. Com `teto_global` ligado, vale para
consultas secundárias do site (não o painel, não REST, não WP-CLI, não
cron). Ação `consulta_leve_teto_aplicado` avisa cada corte (o perfil mostra).

Nunca: remover `SQL_CALC_FOUND_ROWS` de paginação real ou tirar `ORDER BY`
(paginação e contagem inventadas mentem para o cliente e para o Google).

### 5.2 Carga em lote (`primar.php`)

- `consulta_leve_primar( $posts_ou_ids, array $o = array() )`: posts
  (`_prime_post_caches`), metas, termos (`update_object_term_cache`),
  miniaturas (o anexo e as metas dele), autores (`cache_users`), pais.
  Opções `meta`, `termos`, `miniaturas`, `autores`, `pais` (bool). Lista
  com teto de 1000 IDs por chamada, em pedaços de 200.
- `consulta_leve_primar_opcoes( array $nomes )`: `wp_prime_option_caches()`
  (WP 6.4+), nada com cache de objetos externo.
- Carga automática no `after_setup_theme` (prioridade 1) da lista
  `opcoes_lote` mais o filtro `consulta_leve_opcoes_lote`. Origem: loja B
  `prime-opcoes.php` (home 82 para 56 consultas).

### 5.3 Paginação por cursor (`cursor.php`)

Chave composta `(post_date, ID)` ou `(ID)`, sem OFFSET:

- `consulta_leve_cursor_args( array $args, string $cursor = '', string $ordem = 'data' )`:
  marca a consulta, `orderby` fixo (`date DESC, ID DESC` ou `ID DESC`),
  `no_found_rows => true`, pede `posts_per_page + 1` para saber se há mais.
- `consulta_leve_cursor_resultado( WP_Query $q )`: `posts` sem o extra e
  `proximo` (cursor ou `''`).
- Cursor = `hex(json) . '.' . hmac` com `wp_hash()`; cursor alterado ou de
  outra ordem é recusado (volta ao começo). Sem `base64_decode`.
- `posts_where` (filtro só na consulta marcada) usa `$wpdb->prepare`.

### 5.4 Contagem (`contagem.php`)

`consulta_leve_contar( array $args, string $escopo = 'publico' )`: perfil
`contagem`, `found_posts` guardado no cache versionado (dependência
`posts`). `consulta_leve_contar_status( $tipo )`: `wp_count_posts()` no
mesmo cache.

### 5.5 Lotes (`lotes.php`)

`consulta_leve_em_lotes( array $o, callable $fazer )`: anda por
`ID > cursor ORDER BY ID LIMIT lote` (lote 1 a 500, padrão 200), carrega o
lote em cache antes de chamar `$fazer( $ids )`, para no orçamento de tempo
(padrão 20 s) e devolve `array( 'cursor' => int, 'fim' => bool, 'feitos' => int )`
para continuar depois. Opção `adiar_contagem` liga
`wp_defer_term_counting()` com `finally`. Origem: CRM interno
`busca-indice.php` e painel de catálogo `jobs/fila.php`.

## 6. Cache versionado (`nucleo/cache.php`)

`consulta_leve_lembrar( string $grupo, $chave, callable $calcular, array $o = array() )`.

- Chave final: `consulta_leve_` + grupo + versão do grupo + versões das
  dependências + md5 da chave. Dependências (`deps`): `posts`, `terms`,
  `comments`, `users` usam `wp_cache_get_last_changed()` do núcleo, que
  o próprio WordPress muda a cada alteração; nada a invalidar à mão.
- `escopo`: `publico` (padrão) ou `usuario` (acrescenta ID e papéis):
  dado de uma pessoa nunca chega a outra.
- Onde guarda: cache de objetos externo (grupo `consulta_leve`) quando
  existe; senão transient, só se o valor serializado couber em 64 KB;
  sempre um memo por requisição (`static`).
- `ttl` (padrão `cache_ttl`). `false`/`null` calculado não é guardado.
- `consulta_leve_cache_tocar( $grupo )` muda a versão do grupo (uma opção
  `consulta_leve_cache_versoes`, autoload desligado). Chaves velhas param
  de ser lidas e expiram sozinhas: nunca varrer `wp_options`.
- Correria (muitos pedidos ao mesmo tempo com o cache vazio): opção
  `travar => true` usa a trava de 3.4 e serve a última cópia boa
  (`consulta_leve_ultimo_` + hash, 10 min) enquanto outro calcula.
  Origem: CRM interno `metricas.php`.

## 7. Tabela de busca própria (`indices/busca.php`)

Desligada até alguém escolher os tipos (`indice_busca` + `indice_tipos`).

### 7.1 Esquema

`{prefix}consulta_leve_busca`, criada com `dbDelta` (uma coluna por linha,
dois espaços depois de `PRIMARY KEY`):

```
post_id bigint(20) unsigned NOT NULL,
post_type varchar(20) NOT NULL,
post_status varchar(20) NOT NULL,
post_date datetime NOT NULL,
modificado datetime NOT NULL,
titulo text NOT NULL,
texto longtext NOT NULL,
texto_norm longtext NOT NULL,
PRIMARY KEY  (post_id),
KEY tipo_status_data (post_type,post_status,post_date),
KEY modificado (modificado)
```

FULLTEXT `cl_ft (titulo,texto)` fora do `dbDelta`, por `ALTER TABLE`,
resultado `'1'`/`'0'` em `consulta_leve_busca_fulltext`. `texto_norm`
(minúsculas, sem acento) serve o `LIKE` de reserva. Texto limitado a
200 mil caracteres.

### 7.2 Estados

`consulta_leve_busca_estado()`: `desligada`, `sem_tabela`, `construindo`,
`pronta`. Só `pronta` é usada nas consultas. Opções (autoload desligado):
`consulta_leve_busca_versao_tabela`, `consulta_leve_busca_construida`,
`consulta_leve_busca_reconstrucao` (geração, cursor, contagem).

### 7.3 Sincronia

Fila por requisição em `save_post`, `deleted_post`, `trashed_post`,
`untrashed_post`, `set_object_terms`, `added/updated/deleted_post_meta` (só
chaves de `indice_metas`), gravada no `shutdown` com `REPLACE INTO`. Fila
acima de 500 vira reconstrução. Post que saiu dos tipos ou foi apagado sai
da tabela (é cache: o post continua a fonte da verdade). Cada gravação
muda a versão do grupo de cache `busca`.

### 7.4 Reconstrução

Cron em lotes de 200 por cursor de ID, com geração (cadeia velha para
sozinha) e trava; lote carrega posts e metas em cache antes. No fim, linhas
órfãs saem com `LEFT JOIN ... IS NULL`. Conferência diária barata:
`COUNT(*)` e `MAX(post_modified_gmt)` contra a tabela; só reconstrói se
diferente. Painel tem o botão Reconstruir; WP-CLI
`wp consulta-leve busca reconstruir`.

### 7.5 Consulta

`consulta_leve_busca_ids( string $termo, array $o )`: termos de 3+ letras
por `MATCH ... AGAINST ('+termo*' IN BOOLEAN MODE)` (operadores do usuário
removidos); termo curto, stopword ou sem FULLTEXT vai para `LIKE` em
`texto_norm` com `esc_like`. Filtros fixos (`tipos`, `status`), ordem de
uma lista fechada (`relevancia`, `data`), teto de 50 por página, cursor.
Resultado em cache versionado 60 s.

`indice_na_busca` (desligado): a busca do núcleo dos tipos indexados troca
o `LIKE` em `wp_posts` por `ID IN (ids da tabela)` limitado a 500 IDs,
mantendo ordenação e paginação do WordPress. Tabela fora de `pronta`: busca
do núcleo intocada.

## 8. Busca do núcleo (`busca/busca.php`)

### 8.1 Busca contida

Em `pre_get_posts` (prioridade 5), consulta com `s` no site (não painel):

- termo normalizado: espaços, `busca_max` letras, `busca_palavras`
  palavras; abaixo de `busca_min` letras a consulta devolve vazio sem ir
  ao banco (`post__in => array( 0 )`);
- `post_search_columns` (WP 6.2+): interseção com a escolha de
  `busca_colunas`; nunca acrescenta coluna;
- `wp_robots`: `noindex, follow` em `is_search()` (`busca_noindex`);
- limite por IP (`busca_limite` por minuto): passou, resposta 429 com
  `Retry-After: 60`. IP = `REMOTE_ADDR` (filtro
  `consulta_leve_ip` para proxy confiável).

### 8.2 Componente de busca rápida

Shortcode `[consulta_leve_busca tipos="post,page" limite="8"]` e o script
`assets/js/busca.js` (IIFE, sem dependência): espera 300 ms, mínimo de
letras igual a `busca_min`, `AbortController` cancela o pedido anterior,
resultado montado com `textContent`. Só aparece com a REST ligada; sem ela,
o shortcode imprime um formulário de busca comum (SafeWidget).

## 9. REST (`rest/rest.php`)

`GET /wp-json/consulta-leve/v1/busca`, pública, desligada por padrão.

- Entrada: `termo` (texto, 2 a 80), `tipos` (lista; só tipos públicos e
  pesquisáveis), `por_pagina` (1 a 20, padrão 8), `cursor`.
- Saída: `itens: [{ id, titulo, url, tipo, data }]`, `proximo`. Só post
  `publish`, sem senha, de tipo público.
- Usa a tabela de busca quando `pronta`; senão `WP_Query` perfil `ids`,
  colunas `post_title`, cursor.
- Cache versionado 60 s; `ETag` sha256 do corpo; `If-None-Match` igual
  devolve 304 sem corpo; `Cache-Control: public, max-age=60`.
- Limite `rest_por_minuto` por IP: 429 `consulta_leve_limite`.
- Erros: `WP_Error( 'consulta_leve_<motivo>', texto, array( 'status' => N ) )`.

`consulta_leve_rest_etag( WP_REST_Response $r, WP_REST_Request $q, string $cache_control )`
fica disponível para rotas do tema. Comparação aceita lista, `*` e `W/`,
com `hash_equals`. Origem: CRM interno `api/dashboard.php`.

## 10. Diagnóstico

### 10.1 Perfil (`diagnostico/perfil.php`)

Porte de CRM interno `core/perfil.php` e loja A
`inc/perf/perfil-consultas.php`. Liga só com `X-Consulta-Leve-Perfil: 1`
(resumo) ou `explain`, **e** cookie de login de quem tem `manage_options`
(conferido com `wp_validate_auth_cookie`, e de novo com
`current_user_can` na saída). Define `SAVEQUERIES` só se ninguém definiu.
Saída: `Server-Timing` e `X-Consulta-Leve-Perfil` (JSON em base64, até
3000 bytes) com consultas agrupadas por forma, contagem `n` (N+1 aparece
aqui), tempo, quem chamou e, no `explain`, EXPLAIN dos 6 SELECT mais caros
que o WordPress rodou (nunca SQL da requisição) e o ambiente. Resposta com
`Cache-Control: no-store, private`. Nada é gravado.

### 10.2 Diagnóstico do banco (`diagnostico/diagnostico.php`)

Só leitura. `consulta_leve_diagnostico()` devolve: versões (PHP, banco,
WordPress), motor (MySQL, MariaDB ou SQLite), cache de objetos (tipo e
**latência medida**: 20 idas e voltas; acima de 5 ms por ida, alerta, como
o Redis mal configurado que custou 1 s por pedido no CRM interno),
OPcache, `DISABLE_WP_CRON`, `WP_POST_REVISIONS`, opções com autoload
(quantidade, KB e as 10 maiores), tamanho e linhas das tabelas `posts`,
`postmeta`, `options`, `comments`, `termmeta`, `usermeta`, revisões,
rascunhos automáticos, lixeira, transients vencidos, estado dos índices e
da tabela de busca, e recomendações em texto. Cache de 5 min no painel,
botão "Medir de novo".

### 10.3 Monitor (`diagnostico/monitor.php`)

Item na barra do admin para `manage_options`: "N consultas, X ms".
Some com o Query Monitor ativo.

## 11. Índices (`indices/indices.php`)

Nunca automáticos. Lista fechada de candidatos, cada um com tabela, nome
`cl_...`, colunas, motivo e quando ajuda:

| ID | Tabela | Colunas | Ajuda em |
|---|---|---|---|
| `meta_chave_valor` | postmeta | `meta_key(32), meta_value(32)` | `meta_query` de igualdade em lista grande |
| `meta_post_chave` | postmeta | `post_id, meta_key(32)` | `JOIN` de `meta_query` e leitura de uma meta por post |
| `posts_tipo_status_ordem` | posts | `post_type, post_status, menu_order, ID` | listas ordenadas por `menu_order` (kanban, catálogo) |
| `posts_autor_tipo` | posts | `post_author, post_type, post_status, post_date` | "minhas demandas", listas por autor |

- Estado de cada um: `criado` (índice `cl_` presente), `coberto` (outro
  índice começa pelas mesmas colunas), `ausente`, `sem_suporte` (SQLite).
  Leitura por `INFORMATION_SCHEMA.STATISTICS` com `prepare` e igualdade.
- Criar: botão no painel (nonce + `manage_options`) ou WP-CLI. Antes, o
  painel mostra linhas da tabela e o EXPLAIN de uma consulta típica. Usa
  `ALGORITHM=INPLACE, LOCK=NONE` e tenta sem eles se o banco recusar.
  Tabela com mais de 1 milhão de linhas pede confirmação extra.
- Remover: só índice com prefixo `cl_` da lista. Nunca outro.
- Registro de cada ação (quem, quando, resultado) na opção
  `consulta_leve_registro` (autoload desligado, últimas 50).

## 12. Painel (`admin/admin.php`)

Ferramentas, Consulta Leve (`manage_options`), abas: Diagnóstico, Índices,
Busca, Configurações. Formulários por `admin-post` com nonce próprio por
ação; texto de usuário escapado; mensagens por código curto (nada da URL
vira texto). Estilo do admin do WordPress (`widefat`, `notice`), sem
enfeite.

## 13. WP-CLI (`cli/cli.php`)

`wp consulta-leve diagnostico`, `wp consulta-leve indices listar|criar <id>|remover <id>`,
`wp consulta-leve busca status|reconstruir`.

## 14. Atualização

Igual ao Rastro (`docs/ATUALIZADOR.md`): versões assinadas Ed25519 no
GitHub, conferência de tamanho, SHA-256 e assinatura, atualização
automática só depois de 48 h, sem token.

## 15. Segurança (resumo; detalhe em `SECURITY.md`)

- Toda escrita: `current_user_can( 'manage_options' )` + nonce.
- SQL sempre com `$wpdb->prepare`; nomes de tabela e coluna de lista
  fechada; nunca SQL vindo da requisição.
- REST pública só lê post publicado de tipo público, com teto, limite por
  IP e entrada validada.
- Nenhum segredo no repositório; nenhuma chamada externa além do
  atualizador.
- Sem `eval`, sem `base64_decode`, sem drop-in, sem mexer em `wp-config.php`.

## 16. Testes

`node tests/run.js`: `php -l` (com `php` no PATH), `node --check`,
contratos estáticos `tests/validate-*-contract.js` e testes PHP sem
WordPress em `tests/php/` (stubs em `tests/php/stubs.php`). Teste de
verdade no Playground central (`tools/local/central.mjs`, porta 9470) com
a semente de 6 mil posts.

## 17. Reuso

| Item | Origem | Decisão |
|---|---|---|
| Carregador SafeWidget, guarda de cópia embutida | rastro `rastro.php` | reusado |
| Trava por opção | CRM interno `core/trava.php`, painel `core/trava.php` | adaptado |
| Cache versionado | loja C `inc/core/cache.php`, CRM interno `api/busca.php` | adaptado + `last_changed` do núcleo |
| Última cópia boa | CRM interno `data/metricas.php` | adaptado |
| Carga em lote de caches | CRM interno `demandas.php`, loja A `query-cache.php` | generalizado |
| Carga de opções em lote | loja B `prime-opcoes.php` | generalizado |
| Tabela de busca, fila no shutdown, reconstrução por cursor | CRM interno `data/busca-indice.php` | generalizado por tipo de post |
| FULLTEXT fora do dbDelta com reserva LIKE | CRM interno `busca-indice.php`, `api/busca.php` | reusado |
| Lotes com orçamento de tempo | painel `jobs/fila.php` | adaptado |
| Busca contida, debounce, AbortController | loja C `cabecalho.php`/`cabecalho.js`, CRM interno `busca.js` | adaptado |
| ETag e 304 | CRM interno `api/dashboard.php` | reusado |
| Perfil de consultas | loja A `inc/perf/perfil-consultas.php` | porte |
| Regras de índice (EXPLAIN + OK, prefixo próprio, STATISTICS) | loja A `METODOLOGIAS-SCALABILITY-PRO.md` 7.6 | aplicado |
| Atualizador assinado, release, chave | rastro `atualizacao/`, `tools/` | porte |
| Playground central | loja C `tools/local/central.mjs` | porte |
| Índice em `wp_postmeta` | CRM interno contrato 5.6 (não implementado lá) | candidato manual |
| Paginação inventada, SQL de terceiros, drop-in | Scalability Pro | recusado |
