<?php
/**
 * Testes da busca contida, do shortcode e da REST (contrato, seções 8 e 9).
 *
 * @package ConsultaLeve
 */

require __DIR__ . '/stubs.php';

class WP_Query { // phpcs:ignore
	public $query_vars = array();
	public $query      = array();
	public function __construct( $a = array() ) {
		$this->query_vars = $a;
		$this->query      = $a;
	}
	public function get( $k, $p = '' ) {
		return $this->query_vars[ $k ] ?? $p;
	}
	public function set( $k, $v ) {
		$this->query_vars[ $k ] = $v;
	}
}
class WP_REST_Response { // phpcs:ignore
	public $dados;
	public $status;
	public $cabecalhos = array();
	public function __construct( $d = null, $s = 200 ) {
		$this->dados  = $d;
		$this->status = $s;
	}
	public function get_data() {
		return $this->dados;
	}
	public function get_status() {
		return $this->status;
	}
	public function header( $k, $v ) {
		$this->cabecalhos[ $k ] = $v;
	}
}
class CL_Pedido { // phpcs:ignore
	public $h;
	public function __construct( $h = array() ) {
		$this->h = $h;
	}
	public function get_header( $k ) {
		return $this->h[ $k ] ?? null;
	}
}
function shortcode_atts( $padrao, $atts, $nome = '' ) { // phpcs:ignore
	return array_merge( $padrao, array_intersect_key( $atts, $padrao ) );
}
function add_shortcode( $n, $cb ) { // phpcs:ignore
}
function home_url( $p = '' ) { // phpcs:ignore
	return 'https://exemplo.test' . $p;
}
function rest_url( $p = '' ) { // phpcs:ignore
	return 'https://exemplo.test/wp-json/' . $p;
}
function wp_enqueue_script( $h ) { // phpcs:ignore
	$GLOBALS['cl_t']['enfileirados'][] = $h;
}
function wp_enqueue_style( $h ) { // phpcs:ignore
	$GLOBALS['cl_t']['enfileirados'][] = $h;
}
function is_search() { // phpcs:ignore
	return ! empty( $GLOBALS['cl_t']['e_busca'] );
}

add_filter(
	'consulta_leve_modulos',
	static function () {
		return array( 'nucleo/config.php', 'nucleo/coexistencia.php', 'nucleo/trava.php', 'nucleo/cache.php', 'consultas/argumentos.php', 'consultas/cursor.php', 'consultas/lotes.php', 'busca/busca.php', 'rest/rest.php' );
	}
);
require __DIR__ . '/../../consulta-leve/consulta-leve.php';

/* Termo --------------------------------------------------------------------- */

confere( 'termo sem tags e espaços', consulta_leve_busca_termo_limpo( "  <b>casa</b>   de\n praia " ), 'casa de praia' );
confere( 'termo com no máximo 6 palavras', consulta_leve_busca_termo_limpo( 'a b c d e f g h' ), 'a b c d e f' );
confere( 'termo com no máximo 80 letras', mb_strlen( consulta_leve_busca_termo_limpo( str_repeat( 'ção', 50 ) ) ), 80 );
confere( 'letras sem espaços, com acento', consulta_leve_busca_letras( 'ã é' ), 2 );

/* pre_get_posts ------------------------------------------------------------- */

$q = new WP_Query( array( 's' => '  x ' ) );
consulta_leve_busca_pre_get_posts( $q );
confere( 'termo curto: lista vazia sem banco', $q->get( 'post__in' ), array( 0 ) );
$q = new WP_Query( array( 's' => 'casa   azul' ) );
consulta_leve_busca_pre_get_posts( $q );
confere( 'termo bom segue', $q->get( 'post__in', 'nada' ), 'nada' );
confere( 'termo limpo gravado', $q->get( 's' ), 'casa azul' );
$GLOBALS['cl_t']['admin'] = true;
$q                        = new WP_Query( array( 's' => 'x' ) );
consulta_leve_busca_pre_get_posts( $q );
confere( 'painel intocado', $q->get( 'post__in', 'nada' ), 'nada' );
$GLOBALS['cl_t']['admin'] = false;

/* Colunas ------------------------------------------------------------------- */

$q      = new WP_Query( array( 's' => 'casa' ) );
$nucleo = array( 'post_title', 'post_excerpt', 'post_content' );
confere( 'padrão: colunas do núcleo', consulta_leve_busca_colunas( $nucleo, 'casa', $q ), $nucleo );
consulta_leve_config_gravar( array( 'busca_colunas' => 'titulo_resumo' ) );
confere( 'título e resumo', consulta_leve_busca_colunas( $nucleo, 'casa', $q ), array( 'post_title', 'post_excerpt' ) );
confere( 'nunca alarga o que o tema estreitou', consulta_leve_busca_colunas( array( 'post_title' ), 'casa', $q ), array( 'post_title' ) );
confere( 'interseção vazia mantém o atual', consulta_leve_busca_colunas( array( 'post_content' ), 'casa', $q ), array( 'post_content' ) );

/* Robots -------------------------------------------------------------------- */

confere( 'fora da busca: robots intocado', consulta_leve_busca_robots( array( 'max-image-preview' => 'large' ) ), array( 'max-image-preview' => 'large' ) );
$GLOBALS['cl_t']['e_busca'] = true;
confere( 'busca: noindex, follow', consulta_leve_busca_robots( array() ), array( 'noindex' => true, 'follow' => true ) );
$GLOBALS['cl_t']['suporte']['consulta-leve'] = array( array( 'dispensar' => array( 'busca_noindex' ) ) );
confere( 'tema dispensa noindex', consulta_leve_busca_robots( array() ), array() );
$GLOBALS['cl_t']['suporte'] = array();

/* Limite -------------------------------------------------------------------- */

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$bateu                  = false;
for ( $i = 0; $i < 3; $i++ ) {
	$bateu = consulta_leve_limite_bateu( 'teste', 3 );
}
confere( 'dentro do limite', $bateu, false );
confere( 'passou do limite', consulta_leve_limite_bateu( 'teste', 3 ), true );
confere( 'outro balde separado', consulta_leve_limite_bateu( 'outro', 3 ), false );
confere( 'zero desliga', consulta_leve_limite_bateu( 'teste', 0 ), false );
$_SERVER['REMOTE_ADDR'] = 'lixo';
confere( 'IP inválido vira 0.0.0.0', consulta_leve_ip(), '0.0.0.0' );
$GLOBALS['cl_t']['externo'] = true;
$_SERVER['REMOTE_ADDR']     = '198.51.100.1';
consulta_leve_limite_bateu( 'ext', 1 );
confere( 'cache externo atômico', consulta_leve_limite_bateu( 'ext', 1 ), true );
$GLOBALS['cl_t']['externo'] = false;

/* Limite: quem conta e teto de pessoas ---------------------------------------- */

$_SERVER['REMOTE_ADDR'] = '2001:db8:aaaa:bbbb::1';
consulta_leve_limite_bateu( 'v6', 1 );
$_SERVER['REMOTE_ADDR'] = '2001:db8:aaaa:bbbb:ffff::9';
confere( 'IPv6 do mesmo /64 divide o limite', consulta_leve_limite_bateu( 'v6', 1 ), true );
$_SERVER['REMOTE_ADDR'] = '2001:db8:aaaa:cccc::1';
confere( 'outro /64 tem limite próprio', consulta_leve_limite_bateu( 'v6', 1 ), false );

$_SERVER['REMOTE_ADDR']     = '192.0.2.50';
consulta_leve_limite_bateu( 'logado', 1 );
$GLOBALS['cl_t']['usuario'] = 42;
confere( 'logado conta pelo ID, não pelo IP', consulta_leve_limite_bateu( 'logado', 1 ), false );
confere( 'logado também tem limite', consulta_leve_limite_bateu( 'logado', 1 ), true );
$GLOBALS['cl_t']['usuario'] = 0;

for ( $i = 0; $i < 1000; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = '10.0.' . intdiv( $i, 250 ) . '.' . ( $i % 250 );
	consulta_leve_limite_bateu( 'muitos', 5 );
}
$linhas = 0;
foreach ( array_keys( $GLOBALS['cl_t']['transients'] ) as $k ) {
	if ( 0 === strpos( $k, 'consulta_leve_rl_' . substr( md5( 'muitos' ), 0, 12 ) ) ) {
		++$linhas;
	}
}
confere( 'mil IPs, um transient só', $linhas, 1 );
$_SERVER['REMOTE_ADDR'] = '10.9.9.9';
$bateu                  = false;
for ( $i = 0; $i < 50; $i++ ) {
	$bateu = consulta_leve_limite_bateu( 'muitos', 5 );
}
confere( 'acima de mil, contador comum com 10x o limite', $bateu, false );
confere( 'contador comum também bate', consulta_leve_limite_bateu( 'muitos', 5 ), true );

// Busca principal acima do limite: vazia antes de ir ao banco.
consulta_leve_config_gravar( array( 'busca_limite' => 1 ) );
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
consulta_leve_limite_bateu( 'busca', 1 );
class CL_QP extends WP_Query { // phpcs:ignore
	public function is_main_query() {
		return true;
	}
}
$q = new CL_QP( array( 's' => 'casa azul' ) );
consulta_leve_busca_pre_get_posts( $q );
confere( 'acima do limite: consulta vazia', $q->get( 'post__in' ), array( 0 ) );
confere( 'acima do limite: página 429 marcada', ! empty( $GLOBALS['consulta_leve_busca_429'] ), true );

/* Shortcode ----------------------------------------------------------------- */

$html = consulta_leve_busca_shortcode( array( 'tipos' => 'post, <x>page' ) );
confere( 'sem REST: formulário comum', false === strpos( $html, 'data-cl-busca' ), true );
confere( 'formulário funciona sem script', false !== strpos( $html, 'name="s"' ) && false !== strpos( $html, 'action="https://exemplo.test/"' ), true );
consulta_leve_config_gravar( array( 'rest' => true ) );
$html = consulta_leve_busca_shortcode( array( 'tipos' => 'post,<x>page', 'limite' => 99 ) );
confere( 'com REST: busca rápida', false !== strpos( $html, 'data-cl-busca="https://exemplo.test/wp-json/consulta-leve/v1/busca"' ), true );
confere( 'mínimo do componente nunca abaixo de 2', false !== strpos( $html, 'data-cl-min="2"' ), true );
confere( 'tipos saneados', false !== strpos( $html, 'data-cl-tipos="post,xpage"' ), true );
confere( 'limite preso a 20', false !== strpos( $html, 'data-cl-limite="20"' ), true );
confere( 'script só quando usado', $GLOBALS['cl_t']['enfileirados'], array( 'consulta-leve-busca', 'consulta-leve-busca' ) );
confere( 'ids únicos', false !== strpos( consulta_leve_busca_shortcode( array() ), 'cl-busca-3' ), true );

/* ETag ---------------------------------------------------------------------- */

$etag = '"abc"';
confere( 'etag igual', consulta_leve_rest_etag_bate( '"abc"', $etag ), true );
confere( 'etag fraca', consulta_leve_rest_etag_bate( 'W/"abc"', $etag ), true );
confere( 'etag em lista', consulta_leve_rest_etag_bate( '"x", "abc"', $etag ), true );
confere( 'asterisco', consulta_leve_rest_etag_bate( '*', $etag ), true );
confere( 'etag diferente', consulta_leve_rest_etag_bate( '"abd"', $etag ), false );

$r    = new WP_REST_Response( array( 'itens' => array( 1 ) ) );
$r    = consulta_leve_rest_etag( $r, new CL_Pedido(), 'public, max-age=60' );
$tag  = $r->cabecalhos['ETag'];
confere( 'resposta com ETag sha256', 1 === preg_match( '/^"[0-9a-f]{64}"$/', $tag ), true );
confere( 'cache-control pedido', $r->cabecalhos['Cache-Control'], 'public, max-age=60' );
$r2 = consulta_leve_rest_etag( new WP_REST_Response( array( 'itens' => array( 1 ) ) ), new CL_Pedido( array( 'if_none_match' => $tag ) ) );
confere( 'mesma versão: 304', $r2->get_status(), 304 );
confere( '304 sem corpo', consulta_leve_rest_sem_corpo( false, $r2 ), true );
confere( '200 com corpo', consulta_leve_rest_sem_corpo( false, $r ), false );

cl_teste_fim( 'busca-rest' );
