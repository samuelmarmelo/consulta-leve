<?php
/**
 * Testes das consultas: argumentos, carga em lote, cursor, contagem e lotes.
 * Contrato: docs/CONTRATO.md, seção 5.
 *
 * @package ConsultaLeve
 */

require __DIR__ . '/stubs.php';

/* Stubs extras ------------------------------------------------------------- */

class WP_Query { // phpcs:ignore
	public $query      = array();
	public $query_vars = array();
	public $posts      = array();
	public $found_posts = 0;
	public $principal  = false;
	public function __construct( $args = array() ) {
		if ( $args ) {
			$this->query      = $args;
			$this->query_vars = $args;
			$GLOBALS['cl_t']['wp_query'][] = $args;
			$this->found_posts = $GLOBALS['cl_t']['found'] ?? 0;
		}
	}
	public function get( $k, $padrao = '' ) {
		return array_key_exists( $k, $this->query_vars ) ? $this->query_vars[ $k ] : $padrao;
	}
	public function set( $k, $v ) {
		$this->query_vars[ $k ] = $v;
	}
	public function is_main_query() {
		return $this->principal;
	}
}

$GLOBALS['cl_t_posts'] = array();
function _prime_post_caches( $ids, $termos = true, $meta = true ) { // phpcs:ignore
	$GLOBALS['cl_t']['primados'][] = array( array_values( $ids ), $termos, $meta );
}
function get_post( $id ) { // phpcs:ignore
	return $GLOBALS['cl_t_posts'][ $id ] ?? null;
}
function get_post_meta( $id, $k = '', $um = false ) { // phpcs:ignore
	return $GLOBALS['cl_t_meta'][ $id ][ $k ] ?? '';
}
function get_post_field( $campo, $id ) { // phpcs:ignore
	return isset( $GLOBALS['cl_t_posts'][ $id ] ) ? $GLOBALS['cl_t_posts'][ $id ]->$campo : '';
}
function cache_users( $ids ) { // phpcs:ignore
	$GLOBALS['cl_t']['usuarios_cache'] = $ids;
}
function wp_prime_option_caches( $nomes ) { // phpcs:ignore
	$GLOBALS['cl_t']['opcoes_lote'] = $nomes;
}
function wp_count_posts( $tipo ) { // phpcs:ignore
	++$GLOBALS['cl_t']['contou_status'];
	return (object) array(
		'publish' => 12,
		'draft'   => '3',
	);
}
function wp_defer_term_counting( $adiar = null ) { // phpcs:ignore
	$GLOBALS['cl_t']['adiou'][] = $adiar;
}

add_filter(
	'consulta_leve_modulos',
	static function () {
		return array( 'nucleo/config.php', 'nucleo/coexistencia.php', 'nucleo/trava.php', 'nucleo/cache.php', 'consultas/argumentos.php', 'consultas/primar.php', 'consultas/cursor.php', 'consultas/contagem.php', 'consultas/lotes.php' );
	}
);
require __DIR__ . '/../../consulta-leve/consulta-leve.php';

/* Argumentos ---------------------------------------------------------------- */

$a = consulta_leve_args( array( 'post_type' => 'crm_demanda', 'no_found_rows' => false ), 'ids' );
confere( 'perfil ids pede só IDs', $a['fields'], 'ids' );
confere( 'chave do chamador vence', $a['no_found_rows'], false );
confere( 'metas desligadas no ids', $a['update_post_meta_cache'], false );
confere( 'marca do perfil', $a['consulta_leve'], 'ids' );

$a = consulta_leve_args( array( 'posts_per_page' => -1 ), 'lista' );
confere( 'teto em -1', $a['posts_per_page'], 500 );
confere( 'teto avisado', did_action( 'consulta_leve_teto_aplicado' ), 1 );
$a = consulta_leve_args( array( 'posts_per_page' => -1, 'consulta_leve_sem_teto' => true ), 'lista' );
confere( 'sem_teto respeitado', $a['posts_per_page'], -1 );
$a = consulta_leve_args( array( 'numberposts' => -1 ), 'leve' );
confere( 'numberposts -1 também', $a['posts_per_page'], 500 );
confere( 'perfil desconhecido vira lista', consulta_leve_args( array(), 'xyz' )['consulta_leve'], 'lista' );
$c = consulta_leve_args( array(), 'contagem' );
confere( 'contagem conta', $c['no_found_rows'], false );
confere( 'contagem pede 1', $c['posts_per_page'], 1 );

// Marca no WP_Query: completa só o que faltou.
$q = new WP_Query( array( 'consulta_leve' => 'leve', 'update_post_term_cache' => true, 'posts_per_page' => -1 ) );
consulta_leve_pre_get_posts_marcada( $q );
confere( 'marcada: termos do chamador mantidos', $q->get( 'update_post_term_cache' ), true );
confere( 'marcada: metas desligadas', $q->get( 'update_post_meta_cache' ), false );
confere( 'marcada: sem contagem', $q->get( 'no_found_rows' ), true );
confere( 'marcada: teto', $q->get( 'posts_per_page' ), 500 );

$q = new WP_Query( array( 'post_type' => 'post' ) );
consulta_leve_pre_get_posts_marcada( $q );
confere( 'sem marca: intocada', $q->get( 'no_found_rows', 'nada' ), 'nada' );

// Teto global desligado por padrão.
$q = new WP_Query( array( 'posts_per_page' => -1 ) );
consulta_leve_pre_get_posts_teto_global( $q );
confere( 'teto global desligado', $q->get( 'posts_per_page' ), -1 );
consulta_leve_config_gravar( array( 'teto_global' => true ) );
consulta_leve_pre_get_posts_teto_global( $q );
confere( 'teto global ligado corta', $q->get( 'posts_per_page' ), 500 );
$q = new WP_Query( array( 'posts_per_page' => -1, 'fields' => 'ids' ) );
consulta_leve_pre_get_posts_teto_global( $q );
confere( 'teto global poupa consulta de IDs', $q->get( 'posts_per_page' ), -1 );
$q            = new WP_Query( array( 'posts_per_page' => -1 ) );
$q->principal = true;
consulta_leve_pre_get_posts_teto_global( $q );
confere( 'teto global poupa a principal', $q->get( 'posts_per_page' ), -1 );
$GLOBALS['cl_t']['admin'] = true;
$q                        = new WP_Query( array( 'posts_per_page' => -1 ) );
consulta_leve_pre_get_posts_teto_global( $q );
confere( 'teto global poupa o painel', $q->get( 'posts_per_page' ), -1 );
$GLOBALS['cl_t']['admin'] = false;
consulta_leve_config_gravar( array( 'teto_global' => false ) );

/* Carga em lote ------------------------------------------------------------- */

confere( 'ids únicos e positivos', consulta_leve_ids( array( 3, '3', 0, -1, (object) array( 'ID' => 9 ), 'x' ) ), array( 3, 9 ) );

$GLOBALS['cl_t_posts'] = array(
	1 => (object) array( 'ID' => 1, 'post_parent' => 50, 'post_author' => 7, 'post_date' => '2026-11-10 10:00:00' ),
	2 => (object) array( 'ID' => 2, 'post_parent' => 0, 'post_author' => 7, 'post_date' => '2026-11-09 10:00:00' ),
);
$GLOBALS['cl_t_meta']  = array( 1 => array( '_thumbnail_id' => '80' ) );
$GLOBALS['cl_t']['primados'] = array();
consulta_leve_primar( array( 1, 2 ), array( 'miniaturas' => true, 'autores' => true, 'pais' => true ) );
confere( 'posts carregados com termos e metas', $GLOBALS['cl_t']['primados'][0], array( array( 1, 2 ), true, true ) );
confere( 'pai e capa sem termos', $GLOBALS['cl_t']['primados'][1], array( array( 50, 80 ), false, true ) );
confere( 'autores em lote', $GLOBALS['cl_t']['usuarios_cache'], array( 7 ) );

$GLOBALS['cl_t']['primados'] = array();
consulta_leve_primar( range( 1, 450 ) );
confere( 'pedaços de 200', count( $GLOBALS['cl_t']['primados'] ), 3 );
consulta_leve_primar( range( 1, 1500 ) );
confere( 'teto de 1000 por chamada', count( $GLOBALS['cl_t']['primados'] ), 8 );

confere( 'opções em lote saneadas', consulta_leve_primar_opcoes( array( 'woocommerce_x', 'nome inválido!', 'woocommerce_x' ) ), 1 );
$GLOBALS['cl_t']['externo'] = true;
confere( 'com cache externo não carrega', consulta_leve_primar_opcoes( array( 'a' ) ), 0 );
$GLOBALS['cl_t']['externo'] = false;

consulta_leve_config_gravar( array( 'opcoes_lote' => array( 'tema_opcao' ) ) );
add_filter(
	'consulta_leve_opcoes_lote',
	static function ( $n ) {
		$n[] = 'outra_opcao';
		return $n;
	}
);
do_action( 'after_setup_theme' );
confere( 'carga automática com filtro', $GLOBALS['cl_t']['opcoes_lote'], array( 'tema_opcao', 'outra_opcao' ) );

/* Cursor -------------------------------------------------------------------- */

$cur = consulta_leve_cursor_codificar( array( 'o' => 'data', 'i' => 42, 'd' => '2026-11-10 10:00:00' ) );
confere( 'cursor ida e volta', consulta_leve_cursor_decodificar( $cur, 'data' ), array( 'o' => 'data', 'i' => 42, 'd' => '2026-11-10 10:00:00' ) );
confere( 'cursor de outra ordem recusado', consulta_leve_cursor_decodificar( $cur, 'id' ), null );
$adulterado = bin2hex( json_encode( array( 'o' => 'data', 'i' => 1, 'd' => '2026-11-10 10:00:00' ) ) ) . substr( $cur, strpos( $cur, '.' ) );
confere( 'cursor alterado recusado', consulta_leve_cursor_decodificar( $adulterado, 'data' ), null );
$injecao = consulta_leve_cursor_codificar( array( 'o' => 'data', 'i' => 1, 'd' => "1' OR 1=1 -- " ) );
confere( 'data fora do formato recusada', consulta_leve_cursor_decodificar( $injecao, 'data' ), null );
confere( 'lixo recusado', consulta_leve_cursor_decodificar( 'zz.zz', 'data' ), null );

$args = consulta_leve_cursor_args( array( 'post_type' => 'post', 'posts_per_page' => 2, 'paged' => 9, 'offset' => 100 ), $cur );
confere( 'pede um a mais', $args['posts_per_page'], 3 );
confere( 'sem offset', isset( $args['offset'] ) || isset( $args['paged'] ), false );
confere( 'filtros ligados', $args['suppress_filters'], false );
confere( 'ordem composta', $args['orderby'], array( 'date' => 'DESC', 'ID' => 'DESC' ) );

$q     = new WP_Query( $args );
$where = consulta_leve_cursor_where( ' AND 1=1', $q );
confere( 'where da posição', $where, " AND 1=1 AND ( wp_posts.post_date < '2026-11-10 10:00:00' OR ( wp_posts.post_date = '2026-11-10 10:00:00' AND wp_posts.ID < 42 ) )" );
confere( 'consulta sem cursor intocada', consulta_leve_cursor_where( ' X', new WP_Query( array( 'a' => 1 ) ) ), ' X' );

$q->posts = array( $GLOBALS['cl_t_posts'][1], $GLOBALS['cl_t_posts'][2], (object) array( 'ID' => 0 ) );
$r        = consulta_leve_cursor_resultado( $q );
confere( 'resultado sem o extra', count( $r['posts'] ), 2 );
confere( 'próximo aponta o último', consulta_leve_cursor_decodificar( $r['proximo'], 'data' ), array( 'o' => 'data', 'i' => 2, 'd' => '2026-11-09 10:00:00' ) );
$q->posts = array( 5 );
confere( 'última página sem próximo', consulta_leve_cursor_resultado( $q )['proximo'], '' );

$ids  = consulta_leve_cursor_args( array( 'posts_per_page' => 1, 'fields' => 'ids' ), '', 'id' );
$q    = new WP_Query( $ids );
$q->posts = array( 2, 1 );
confere( 'ordem por ID', consulta_leve_cursor_decodificar( consulta_leve_cursor_resultado( $q )['proximo'], 'id' ), array( 'o' => 'id', 'i' => 2 ) );

/* Contagem ------------------------------------------------------------------ */

$GLOBALS['cl_t']['found']    = 37;
$GLOBALS['cl_t']['wp_query'] = array();
confere( 'contagem', consulta_leve_contar( array( 'post_type' => 'post', 'paged' => 3 ) ), 37 );
confere( 'contagem guardada', consulta_leve_contar( array( 'paged' => 9, 'post_type' => 'post' ) ), 37 );
confere( 'uma consulta só', count( $GLOBALS['cl_t']['wp_query'] ), 1 );
confere( 'perfil contagem usado', $GLOBALS['cl_t']['wp_query'][0]['no_found_rows'], false );

$GLOBALS['cl_t']['contou_status'] = 0;
confere( 'status contados', consulta_leve_contar_status( 'post' ), array( 'publish' => 12, 'draft' => 3 ) );
consulta_leve_contar_status( 'post' );
confere( 'status guardados', $GLOBALS['cl_t']['contou_status'], 1 );

/* Lotes --------------------------------------------------------------------- */

$GLOBALS['cl_t']['sql'] = array(
	'/SELECT ID FROM wp_posts WHERE post_type IN/' => static function ( $sql ) {
		preg_match( '/ID > (\d+) ORDER BY ID ASC LIMIT (\d+)/', $sql, $m );
		$todos = range( 1, 5 );
		return array_slice( array_values( array_filter( $todos, static function ( $id ) use ( $m ) {
			return $id > (int) $m[1];
		} ) ), 0, (int) $m[2] );
	},
);
$vistos  = array();
$estado  = consulta_leve_em_lotes(
	array( 'tipos' => array( 'post', 'page' ), 'lote' => 2, 'adiar_contagem' => true ),
	static function ( $ids ) use ( &$vistos ) {
		$vistos = array_merge( $vistos, $ids );
	}
);
confere( 'lotes passam por todos', $vistos, array( 1, 2, 3, 4, 5 ) );
confere( 'lotes terminam', $estado['fim'], true );
confere( 'cursor no último', $estado['cursor'], 5 );
confere( 'contagem de termos adiada e devolvida', $GLOBALS['cl_t']['adiou'], array( true, false ) );
$sql = implode( "\n", $GLOBALS['cl_t']['consultas'] );
confere( 'tipos e status preparados', false !== strpos( $sql, "post_type IN ('post','page') AND post_status IN ('publish')" ), true );

$estado = consulta_leve_em_lotes(
	array( 'cursor' => 2, 'lote' => 2 ),
	static function () {
		throw new RuntimeException( 'falhou no lote' );
	}
);
confere( 'erro não avança o cursor', $estado['cursor'], 2 );
confere( 'erro registrado', $estado['erro'], 'falhou no lote' );
confere( 'tipos inválidos terminam sem SQL', consulta_leve_em_lotes( array( 'tipos' => array( '!!!' ) ), 'is_int' )['fim'], true );

cl_teste_fim( 'consultas' );
